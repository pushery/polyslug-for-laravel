<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Polyslug\Contracts\SitemapSource;
use Polyslug\Contracts\SitemapStorage;
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
 * source remain, has its source cut anew, and so does a source whose keys came out of byte order.
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

    /**
     * @param  list<SitemapSource>  $sources  each with a name of its own
     * @param  string|null  $root  the URL the parts are served under, which the index names each part
     *                             by: under `https://shop.example/sitemaps` the part `pages-1.xml` is
     *                             `https://shop.example/sitemaps/pages-1.xml`
     * @param  int|null  $maxUrls  addresses per part, `polyslug.sitemap.max_urls` where null
     * @param  int|null  $maxBytes  bytes per part, `polyslug.sitemap.max_bytes` where null
     */
    public function __construct(
        array $sources,
        private SitemapStorage $storage,
        ?string $root = null,
        ?int $maxUrls = null,
        ?int $maxBytes = null,
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
    }

    /**
     * Writes every source whole, and removes from the storage the parts of a source this sitemap no
     * longer has.
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
     * The source is cut anew where it has no parts yet, where its keys came out of byte order, and
     * where a part no longer fits its ceilings or emptied while others remain; a storage that keeps
     * no record of its parts has the whole sitemap written. A key no record carries any more builds
     * its part without that record, and a new key lands in the part whose range holds it.
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

            array_push($written, ...$refreshed);
        }

        return $written;
    }

    /**
     * Cuts a source into parts: its records in the byte order of their keys, a new part where the
     * next record would take the current one past a ceiling, and the parts an earlier cut wrote
     * beyond the last one removed.
     *
     * @return list<SitemapPart> the parts written, without their bodies
     */
    private function cut(SitemapSource $source): array
    {
        $parts = [];
        $entries = [];
        $bytes = 0;
        $lastModified = null;
        $startsAt = '';
        $previous = null;
        $ordered = true;

        foreach ($source->records() as $record) {
            $ordered = $ordered && ($previous === null || strcmp($record->key, $previous) > 0);
            $previous = $record->key;
            $own = self::entries($record);

            if ($own === []) {
                continue;
            }

            $size = $this->bytes($own);

            // Checked before the record is added, and only when the part already holds something: a
            // record wider than a whole part is still written, alone in a part of its own.
            if ($entries !== [] && ! $this->fits(count($entries) + count($own), $bytes + $size)) {
                $parts[] = $this->put($source->name(), count($parts) + 1, $startsAt, $entries, $lastModified);
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
            $parts[] = $this->put($source->name(), count($parts) + 1, $ordered ? $startsAt : null, $entries, $lastModified);
        }

        $this->storage->trim($source->name(), count($parts));

        return $parts;
    }

    /**
     * Builds one part again from the records its range holds now.
     *
     * @param  list<SitemapPart>  $parts  of the source, in the order of their numbers
     * @return list<SitemapPart>|null the part written, none where the only part of the source
     *                                emptied and went, and null where the source is to be cut anew
     */
    private function refresh(SitemapSource $source, array $parts, int $position): ?array
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

        if (! $ordered || $outgrown || ($entries === [] && count($parts) > 1)) {
            return null;
        }

        if ($entries === []) {
            $this->storage->trim($source->name(), 0);

            return [];
        }

        return [$this->put($source->name(), $part->number, $part->startsAt, $entries, $lastModified)];
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
     * @param  list<string>  $entries
     */
    private function put(string $source, int $number, ?string $startsAt, array $entries, ?DateTimeInterface $lastModified): SitemapPart
    {
        $part = new SitemapPart($source, $number, $startsAt, count($entries), $lastModified?->format(DATE_ATOM), self::urlset($entries));

        $this->storage->put($part);

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
     */
    private function ceiling(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
