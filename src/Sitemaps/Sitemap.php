<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Polyslug\Contracts\SitemapSource;
use Polyslug\Contracts\SitemapStorage;
use Polyslug\Contracts\StagesSitemapParts;
use Polyslug\Exceptions\CouldNotBuildSitemap;
use Polyslug\Polyslug;
use Throwable;

/**
 * A sitemap: the records of its sources, cut into parts and kept in a storage, written whole or one
 * part at a time.
 *
 * Each source is cut by the keys of its records, in their byte order. A part holds the records from
 * the key it starts at up to the key the next part of its source starts at, at most `max_urls`
 * addresses and `max_bytes` bytes, and the addresses of one record always share a part. Since a part
 * is a range of keys rather than a position, a change to one record builds again the one part whose
 * range holds its key. A part that outgrows its ceilings, or that empties while others of its
 * source remain, has its source cut anew from where that part starts, the parts before it as they
 * were. A source whose keys came out of byte order is cut anew whole.
 *
 * A cut fills each part to the share of its ceilings `fill` names. At 1 a part is cut full; below
 * it each part keeps room for its records to grow, so a record that gains an address builds its
 * part again in place.
 *
 * recut() cuts a source anew over several writes, a few parts in each, for a source too large to
 * read in one: the new parts are staged beside the ones served, and the last write serves them.
 *
 * The root, the sources and the storage belong to one sitemap, so each tenant of an application can
 * have its own: its own host in the index, its own records, its own rows or files. Two writes of one
 * sitemap must not interleave. A storage that several processes write makes the second wait while
 * it reads the parts, as DatabaseSitemapStorage does.
 */
final readonly class Sitemap
{
    /**
     * How much of the byte budget the envelope may take.
     *
     * The budget is checked against the entries alone, because they are the part that grows. The
     * reserve covers the XML declaration, the opening `<urlset>` with both namespaces and the closing
     * tag, about 190 bytes, with room left over, so a document never crosses the limit by the width
     * of its own wrapper.
     */
    public const int ENVELOPE_RESERVE = 1024;

    private const string URLSET_OPENS = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

    private const string URLSET_CLOSES = '</urlset>'."\n";

    /** @var list<SitemapSource> */
    private array $sources;

    private ?string $root;

    private int $maxUrls;

    private int $maxBytes;

    private float $fill;

    /**
     * @param  list<SitemapSource>  $sources  each with a name of its own
     * @param  string|null  $root  the URL the parts are served under, which the index names each part
     *                             by: under `https://shop.example/sitemaps` the part `pages-1.xml` is
     *                             `https://shop.example/sitemaps/pages-1.xml`
     * @param  int|null  $maxUrls  addresses per part, `polyslug.sitemap.max_urls` where null
     * @param  int|null  $maxBytes  bytes per part, `polyslug.sitemap.max_bytes` where null
     * @param  float|null  $fill  the share of each ceiling a cut fills a part to, above 0 and at
     *                            most 1, `polyslug.sitemap.fill` where null
     */
    public function __construct(
        array $sources,
        private SitemapStorage $storage,
        ?string $root = null,
        ?int $maxUrls = null,
        ?int $maxBytes = null,
        ?float $fill = null,
    ) {
        $names = [];

        foreach ($sources as $source) {
            if (in_array($source->name(), $names, true)) {
                throw CouldNotBuildSitemap::sourceNamedTwice($source->name());
            }

            $names[] = $source->name();
        }

        $config = Container::getInstance()->make(ConfigRepository::class);

        $this->sources = $sources;
        $this->root = $root === null || $root === '' ? null : rtrim($root, '/');
        $this->maxUrls = $this->ceiling($maxUrls ?? $config->get('polyslug.sitemap.max_urls'), 50_000);
        $this->maxBytes = $this->ceiling($maxBytes ?? $config->get('polyslug.sitemap.max_bytes'), 50 * 1024 * 1024);
        $this->fill = $this->share($fill ?? $config->get('polyslug.sitemap.fill'));
    }

    /**
     * Writes every source whole, and removes from the storage the parts of a source this sitemap no
     * longer has. A cut of a source that runs over several writes is dropped.
     *
     * @return list<SitemapPart> the parts written, without their bodies
     */
    public function build(): array
    {
        return $this->write(fn (): array => $this->buildFrom($this->storage->parts()));
    }

    /**
     * Builds again the parts of a source that hold the records with the keys given, each key in the
     * last part that starts at or before it.
     *
     * The source is cut anew where it has no parts yet or its keys came out of byte order, and from
     * the part on that no longer fits its ceilings or emptied while others remain; a storage that
     * keeps no record of its parts has the whole sitemap written. A key no record carries any more
     * builds its part without that record, and a new key lands in the part whose range holds it.
     *
     * @param  list<string>  $keys
     * @return list<SitemapPart> the parts written, without their bodies
     */
    public function rebuild(string $source, array $keys): array
    {
        $subject = $this->source($source) ?? throw CouldNotBuildSitemap::unknownSource($source);

        return $this->write(fn (): array => $this->rebuildFrom($this->storage->parts(), $subject, $keys));
    }

    /**
     * Cuts a source anew over several writes, each writing at most the parts given, and serves the
     * new parts once the last write is in. Until then the index and every document are the parts
     * served before.
     *
     * A call without a state starts the cut and drops a cut of the source left unfinished. Each call
     * returns where the cut stands, the next call continues from it, and the call that reaches the
     * end of the source serves the new parts in place of the old ones. A cut that is not continued
     * leaves the parts served as they were. A source whose keys come out of byte order is cut anew
     * whole in the call that finds them so, since no range of them can be read in steps.
     *
     * A rebuild while a cut runs builds again the new parts that hold its keys as well, and build()
     * drops the cut.
     *
     * @param  int  $parts  how many parts each call writes at most, at least 1
     * @param  SitemapRecut|null  $from  where the cut stands, as the previous call returned it, or
     *                                   null to start it
     *
     * @throws CouldNotBuildSitemap where the storage cannot hold the new parts beside the ones it
     *                              serves, or the cut $from stands in was dropped
     */
    public function recut(string $source, int $parts, ?SitemapRecut $from = null): SitemapRecut
    {
        $subject = $this->source($source) ?? throw CouldNotBuildSitemap::unknownSource($source);
        $storage = $this->storage;

        if (! $storage instanceof StagesSitemapParts) {
            throw CouldNotBuildSitemap::cannotStage($storage);
        }

        if ($from instanceof SitemapRecut && $from->done()) {
            return new SitemapRecut($from->source, null, $from->parts);
        }

        return $this->writeStep(fn (): SitemapRecut => $this->recutStep($subject, $storage, max(1, $parts), $from));
    }

    /**
     * The index naming every part the storage holds of this sitemap, in the order of the sources and
     * their numbers, each with its `<lastmod>` where it has one.
     */
    public function index(): string
    {
        return $this->indexOver($this->stored());
    }

    /**
     * The XML of the part served under a name, as the storage names its parts; null where no part of
     * this sitemap has that name.
     */
    public function document(string $name): ?string
    {
        foreach ($this->stored() as $part) {
            if ($this->storage->name($part->source, $part->number) === $name) {
                return $this->storage->body($part->source, $part->number);
            }
        }

        return null;
    }

    /**
     * The `<url>` elements of a record, one for each address it is served at.
     *
     * One element per address rather than per record: a `<url>` is what the protocol counts as a
     * submitted address, and an `<xhtml:link>` inside it is an annotation about that address, never a
     * submission of its own. Every element carries the record's whole set of alternates, its own
     * address among them, because reciprocity is a property of the record and not of one address.
     *
     * `x-default` is an alternate and never a `<loc>`: it names an address the set already holds
     * under a locale. An address served under two codes is submitted once. `<lastmod>` stands
     * between `<loc>` and the alternates, the order the protocol defines for `<url>`.
     *
     * @return list<string>
     */
    public static function entries(SitemapRecord $record): array
    {
        $links = '';

        foreach ($record->alternates as $hreflang => $url) {
            $links .= sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', e(Polyslug::hreflangCode($hreflang)), e($url));
        }

        $lastmod = $record->lastModified instanceof DateTimeInterface
            ? '<lastmod>'.e($record->lastModified->format(DATE_ATOM)).'</lastmod>'
            : '';

        $entries = [];

        foreach ($record->alternates as $hreflang => $url) {
            if ($hreflang !== 'x-default') {
                $entries[$url] = '<url><loc>'.e($url).'</loc>'.$lastmod.$links.'</url>';
            }
        }

        return array_values($entries);
    }

    /**
     * A `<urlset>` document of the entries given, one on each line.
     *
     * @param  list<string>  $entries
     */
    public static function urlset(array $entries): string
    {
        return self::URLSET_OPENS.implode("\n", $entries)."\n".self::URLSET_CLOSES;
    }

    /**
     * Runs a write: published when the work returns, dropped when it throws.
     *
     * @param  Closure(): list<SitemapPart>  $work
     * @return list<SitemapPart>
     */
    private function write(Closure $work): array
    {
        $this->storage->begin();

        try {
            $written = $work();
        } catch (Throwable $failure) {
            $this->storage->rollBack();

            throw $failure;
        }

        $this->storage->commit($this->entry(...));

        return $written;
    }

    /**
     * Runs one write of a cut over several: published when the step returns, dropped when it throws.
     *
     * @param  Closure(): SitemapRecut  $step
     */
    private function writeStep(Closure $step): SitemapRecut
    {
        $this->storage->begin();

        try {
            $state = $step();
        } catch (Throwable $failure) {
            $this->storage->rollBack();

            throw $failure;
        }

        $this->storage->commit($this->entry(...));

        return $state;
    }

    /**
     * @param  list<SitemapPart>|null  $stored
     * @return list<SitemapPart>
     */
    private function buildFrom(?array $stored): array
    {
        $gone = [];

        foreach ($stored ?? [] as $part) {
            if (! $this->source($part->source) instanceof SitemapSource && ! in_array($part->source, $gone, true)) {
                $gone[] = $part->source;
                $this->storage->trim($part->source, 0);
            }
        }

        $written = [];

        foreach ($this->sources as $source) {
            // A build writes each source from its records as they are now, so a cut of it that runs
            // over several writes would serve older parts in place of these: it is dropped.
            if ($this->storage instanceof StagesSitemapParts) {
                $this->storage->discard($source->name());
            }

            array_push($written, ...$this->cut($source));
        }

        return $written;
    }

    /**
     * @param  list<SitemapPart>|null  $stored
     * @param  list<string>  $keys
     * @return list<SitemapPart>
     */
    private function rebuildFrom(?array $stored, SitemapSource $source, array $keys): array
    {
        if ($stored === null) {
            return $this->buildFrom(null);
        }

        $written = $this->rebuildServed($stored, $source, $keys);

        if ($this->storage instanceof StagesSitemapParts) {
            $this->rebuildStaged($this->storage, $source, $keys);
        }

        return $written;
    }

    /**
     * @param  list<SitemapPart>  $stored
     * @param  list<string>  $keys
     * @return list<SitemapPart>
     */
    private function rebuildServed(array $stored, SitemapSource $source, array $keys): array
    {
        $parts = $this->partsOf($source, $stored);

        if ($parts === [] || in_array(null, array_map(static fn (SitemapPart $part): ?string => $part->startsAt, $parts), true)) {
            return $this->cut($source);
        }

        $written = [];

        foreach ($this->holding($parts, $keys) as $position) {
            $refreshed = $this->refresh($source, $parts, $position);

            if ($refreshed === null) {
                return $this->cut($source);
            }

            // From the part that outgrew or emptied on, every part is cut anew, the ones that hold
            // the keys still ahead among them.
            if ($refreshed === false) {
                return [...$written, ...$this->cut($source, $parts[$position])];
            }

            array_push($written, ...$refreshed);
        }

        return $written;
    }

    /**
     * Cuts a source into parts: its records in the byte order of their keys, a new part where the
     * next record would take the current one past the share of a ceiling a cut fills, and the
     * parts an earlier cut wrote beyond the last one removed.
     *
     * From one of its parts, the cut reads the records from where that part starts and writes the
     * parts from its number on, so the parts before it stay as they were and the first one written
     * keeps where that part started.
     *
     * @return list<SitemapPart> the parts written, without their bodies
     */
    private function cut(SitemapSource $source, ?SitemapPart $from = null): array
    {
        $parts = [];
        $entries = [];
        $bytes = 0;
        $lastModified = null;
        $first = $from instanceof SitemapPart ? $from->number : 1;
        $startsAt = $from->startsAt ?? '';
        $previous = null;
        $ordered = true;

        foreach ($source->records($startsAt) as $record) {
            $ordered = $ordered && ($previous === null || strcmp($record->key, $previous) > 0);
            $previous = $record->key;
            $own = self::entries($record);

            if ($own === []) {
                continue;
            }

            $size = $this->bytes($own);

            // Checked before the record is added, and only when the part already holds something: a
            // record wider than a whole part is still written, alone in a part of its own.
            if ($entries !== [] && ! $this->fitsACut(count($entries) + count($own), $bytes + $size)) {
                $parts[] = $this->put($source->name(), $first + count($parts), $startsAt, $entries, $lastModified);
                $entries = [];
                $bytes = 0;
                $lastModified = null;
                $startsAt = $record->key;
            }

            array_push($entries, ...$own);
            $bytes += $size;
            $lastModified = $this->later($lastModified, $record->lastModified);
        }

        if ($entries !== []) {
            // Keys out of byte order leave the ranges unknown, and the last part says so: the next
            // rebuild of the source cuts it anew instead of trusting where each part starts.
            $parts[] = $this->put($source->name(), $first + count($parts), $ordered ? $startsAt : null, $entries, $lastModified);
        }

        $this->storage->trim($source->name(), $first - 1 + count($parts));

        return $parts;
    }

    /**
     * Builds one part again from the records its range holds now.
     *
     * @param  list<SitemapPart>  $parts  of the source, in the order of their numbers
     * @param  StagesSitemapParts|null  $stage  the storage to stage the part in, for a part of a cut
     *                                          that runs; null to write a part that is served
     * @return list<SitemapPart>|false|null the part written, none where the only part of the
     *                                      source emptied and went, false where the source is to
     *                                      be cut anew from this part on, and null where its keys
     *                                      came out of byte order and it is to be cut anew whole
     */
    private function refresh(SitemapSource $source, array $parts, int $position, ?StagesSitemapParts $stage = null): array|false|null
    {
        $part = $parts[$position];
        $next = $parts[$position + 1] ?? null;
        $entries = [];
        $bytes = 0;
        $records = 0;
        $lastModified = null;
        $previous = null;
        $ordered = true;

        foreach ($source->records($part->startsAt ?? '', $next?->startsAt) as $record) {
            $ordered = $ordered && ($previous === null || strcmp($record->key, $previous) > 0);
            $previous = $record->key;
            $own = self::entries($record);

            if ($own === []) {
                continue;
            }

            array_push($entries, ...$own);
            $bytes += $this->bytes($own);
            $records++;
            $lastModified = $this->later($lastModified, $record->lastModified);
        }

        // A part that holds a single record is as small as that record makes it: a cut would write
        // the same part again.
        $outgrown = $records > 1 && ! $this->fits(count($entries), $bytes);

        if (! $ordered) {
            return null;
        }

        if ($outgrown || ($entries === [] && count($parts) > 1)) {
            return false;
        }

        if ($stage instanceof StagesSitemapParts) {
            return $entries === [] ? false : [$this->staging($stage, $source->name(), $part->number, $part->startsAt ?? '', $entries, $lastModified)];
        }

        if ($entries === []) {
            $this->storage->trim($source->name(), 0);

            return [];
        }

        return [$this->put($source->name(), $part->number, $part->startsAt, $entries, $lastModified)];
    }

    /**
     * One write of a cut over several: stages at most $limit parts from where the cut stands, and
     * serves them once the source ends.
     */
    private function recutStep(SitemapSource $source, StagesSitemapParts $storage, int $limit, ?SitemapRecut $from): SitemapRecut
    {
        $name = $source->name();

        if ($from instanceof SitemapRecut) {
            $cursor = $this->cursorOf($storage->staged($name));

            if ($from->source !== $name || ! $cursor instanceof SitemapPart || $cursor->number !== $from->parts + 1 || $cursor->startsAt !== $from->next) {
                throw CouldNotBuildSitemap::recutInterrupted($name);
            }

            $number = $cursor->number;
            $startsAt = (string) $from->next;
        } else {
            $storage->discard($name);
            $number = 1;
            $startsAt = '';
        }

        // Ranges read in steps hold only where the keys come in byte order. Where the parts served
        // already say they do not, or a step finds that they do not, the source is cut anew whole
        // here, and nothing of the cut that ran is kept.
        $served = $this->partsOf($source, $this->storage->parts() ?? []);
        $step = in_array(null, array_map(static fn (SitemapPart $part): ?string => $part->startsAt, $served), true)
            ? null
            : $this->stageFrom($source, $storage, $number, $startsAt, $limit);

        if ($step === null) {
            $storage->discard($name);
            $written = $this->cut($source);

            return new SitemapRecut($name, null, count($written), $written);
        }

        [$written, $next] = $step;
        $parts = $number - 1 + count($written);

        if ($next === null) {
            $storage->promote($name, $parts);

            return new SitemapRecut($name, null, $parts, $written);
        }

        // Where the next part starts, staged as a part without addresses behind the last one written:
        // a rebuild reads the last staged part up to it, and the next call can tell that the cut it
        // continues is still the one staged.
        $storage->stage(new SitemapPart($name, $parts + 1, $next, 0, null, self::urlset([])));

        return new SitemapRecut($name, $next, $parts, $written);
    }

    /**
     * Stages at most $limit parts of a source, the first numbered $number and starting at $startsAt.
     *
     * @return array{list<SitemapPart>, string|null}|null the parts staged and the key the next part
     *                                                    starts at, null where the source ended;
     *                                                    null where its keys came out of byte order
     */
    private function stageFrom(SitemapSource $source, StagesSitemapParts $storage, int $number, string $startsAt, int $limit): ?array
    {
        $written = [];
        $entries = [];
        $bytes = 0;
        $lastModified = null;
        $previous = null;

        foreach ($source->records($startsAt) as $record) {
            if ($previous !== null && strcmp($record->key, $previous) <= 0) {
                return null;
            }

            $previous = $record->key;
            $own = self::entries($record);

            if ($own === []) {
                continue;
            }

            $size = $this->bytes($own);

            if ($entries !== [] && ! $this->fitsACut(count($entries) + count($own), $bytes + $size)) {
                $written[] = $this->staging($storage, $source->name(), $number + count($written), $startsAt, $entries, $lastModified);

                // The record that does not fit starts the next part, so the next call reads from it.
                if (count($written) === $limit) {
                    return [$written, $record->key];
                }

                $entries = [];
                $bytes = 0;
                $lastModified = null;
                $startsAt = $record->key;
            }

            array_push($entries, ...$own);
            $bytes += $size;
            $lastModified = $this->later($lastModified, $record->lastModified);
        }

        if ($entries !== []) {
            $written[] = $this->staging($storage, $source->name(), $number + count($written), $startsAt, $entries, $lastModified);
        }

        return [$written, null];
    }

    /**
     * Builds again the staged parts of a running cut that hold the keys, so a record that changes on
     * the way reaches the new parts as well as the ones served. A key past where the cut has come is
     * read when it gets there. A staged part that no longer fits its ceilings, empties, or reads its
     * keys out of byte order drops the running cut, whose next call then throws.
     *
     * @param  list<string>  $keys
     */
    private function rebuildStaged(StagesSitemapParts $storage, SitemapSource $source, array $keys): void
    {
        $staged = $storage->staged($source->name());
        $cursor = $this->cursorOf($staged);

        if (! $cursor instanceof SitemapPart) {
            return;
        }

        $until = (string) $cursor->startsAt;
        $behind = array_values(array_filter($keys, static fn (string $key): bool => strcmp($key, $until) < 0));

        foreach ($this->holding($staged, $behind) as $position) {
            if (! is_array($this->refresh($source, $staged, $position, $storage))) {
                $storage->discard($source->name());

                return;
            }
        }
    }

    /**
     * The staged part that marks where the next part of a running cut starts: the last one staged,
     * where it names no address, since every part a cut writes names one.
     *
     * @param  list<SitemapPart>  $staged
     */
    private function cursorOf(array $staged): ?SitemapPart
    {
        $last = $staged === [] ? null : $staged[count($staged) - 1];

        return $last instanceof SitemapPart && $last->urls === 0 ? $last : null;
    }

    /**
     * The positions of the parts that hold the keys, in order: each key lies in the last part that
     * starts at or before it, compared byte by byte, and the first part starts before every key.
     *
     * @param  list<SitemapPart>  $parts  of one source, in the order of their numbers
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function holding(array $parts, array $keys): array
    {
        $positions = [];

        foreach ($keys as $key) {
            $holding = 0;

            foreach ($parts as $position => $part) {
                if (strcmp($part->startsAt ?? '', $key) > 0) {
                    break;
                }

                $holding = $position;
            }

            $positions[$holding] = $holding;
        }

        ksort($positions);

        return array_values($positions);
    }

    /**
     * The parts of one source, in the order of their numbers.
     *
     * @param  list<SitemapPart>  $stored
     * @return list<SitemapPart>
     */
    private function partsOf(SitemapSource $source, array $stored): array
    {
        $parts = array_values(array_filter($stored, static fn (SitemapPart $part): bool => $part->source === $source->name()));

        usort($parts, static fn (SitemapPart $one, SitemapPart $other): int => $one->number <=> $other->number);

        return $parts;
    }

    /**
     * The parts the storage holds of this sitemap's sources, in the order of the sources and their
     * numbers.
     *
     * @return list<SitemapPart>
     */
    private function stored(): array
    {
        $stored = $this->storage->parts() ?? throw CouldNotBuildSitemap::keepsNoParts($this->storage);
        $parts = [];

        foreach ($this->sources as $source) {
            array_push($parts, ...$this->partsOf($source, $stored));
        }

        return $parts;
    }

    /**
     * The document a sitemap of these parts is entered by: the index naming them, or an empty
     * `<urlset>` for none.
     *
     * @param  list<SitemapPart>  $parts
     */
    private function entry(array $parts): string
    {
        return $parts === [] ? self::urlset([]) : $this->indexOver($parts);
    }

    /**
     * @param  list<SitemapPart>  $parts
     */
    private function indexOver(array $parts): string
    {
        $entries = '';

        foreach ($parts as $part) {
            // A relative <loc> is invalid in an index, and an invalid index takes the whole
            // submission down, where a missing one only delays it.
            $root = $this->root ?? throw CouldNotBuildSitemap::withoutRoot(count($parts));

            $entries .= '<sitemap><loc>'.e($root.'/'.$this->storage->name($part->source, $part->number)).'</loc>'
                .($part->lastModified === null ? '' : '<lastmod>'.e($part->lastModified).'</lastmod>')
                .'</sitemap>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$entries
            .'</sitemapindex>'."\n";
    }

    private function source(string $name): ?SitemapSource
    {
        foreach ($this->sources as $source) {
            if ($source->name() === $name) {
                return $source;
            }
        }

        return null;
    }

    private function fits(int $urls, int $bytes): bool
    {
        return $urls <= $this->maxUrls && $bytes <= $this->maxBytes - self::ENVELOPE_RESERVE;
    }

    /**
     * Whether a part a cut writes stays within the share of its ceilings `fill` gives it: the full
     * ceilings at 1, and below that each rounded down, at least one address, so its records keep
     * room to grow before a rebuild finds the part outgrown.
     */
    private function fitsACut(int $urls, int $bytes): bool
    {
        if ($this->fill >= 1.0) {
            return $this->fits($urls, $bytes);
        }

        return $urls <= max(1, (int) floor($this->maxUrls * $this->fill))
            && $bytes <= (int) floor(($this->maxBytes - self::ENVELOPE_RESERVE) * $this->fill);
    }

    /**
     * @param  list<string>  $entries
     */
    private function put(string $source, int $number, ?string $startsAt, array $entries, ?DateTimeInterface $lastModified): SitemapPart
    {
        $part = new SitemapPart($source, $number, $startsAt, count($entries), $lastModified?->format(DATE_ATOM), self::urlset($entries));

        $this->storage->put($part);

        return $part->withoutBody();
    }

    /**
     * @param  list<string>  $entries
     */
    private function staging(StagesSitemapParts $storage, string $source, int $number, string $startsAt, array $entries, ?DateTimeInterface $lastModified): SitemapPart
    {
        $part = new SitemapPart($source, $number, $startsAt, count($entries), $lastModified?->format(DATE_ATOM), self::urlset($entries));

        $storage->stage($part);

        return $part->withoutBody();
    }

    /**
     * What the entries cost against the byte budget: each its width and the newline that joins it
     * to the next.
     *
     * @param  list<string>  $entries
     */
    private function bytes(array $entries): int
    {
        $bytes = 0;

        foreach ($entries as $entry) {
            $bytes += strlen($entry) + 1;
        }

        return $bytes;
    }

    private function later(?DateTimeInterface $one, ?DateTimeInterface $other): ?DateTimeInterface
    {
        if (! $one instanceof DateTimeInterface || ! $other instanceof DateTimeInterface) {
            return $one ?? $other;
        }

        return $other > $one ? $other : $one;
    }

    /**
     * A configured ceiling, or the protocol's own where the value is no positive whole number: a zero
     * ceiling would close a part before every record, and a negative one could never be met.
     *
     * @param  mixed  $value  the configured ceiling, which a host may write as any type
     */
    private function ceiling(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * A configured share of the ceilings, or 1 where the value is no number above 0 and at most 1:
     * a share of 0 would close a part before every record, and one above 1 would cut parts a
     * rebuild finds outgrown at once.
     *
     * @param  mixed  $value  the configured share, which a host may write as any type
     */
    private function share(mixed $value): float
    {
        return (is_int($value) || is_float($value)) && $value > 0 && $value <= 1 ? (float) $value : 1.0;
    }
}
