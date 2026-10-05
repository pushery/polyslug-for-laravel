<?php

declare(strict_types=1);

namespace Polyslug\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;
use Polyslug\Polyslug;
use Polyslug\Support\SuccessorChain;
use Throwable;

final class SitemapCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:sitemap
        {--path= : Write the sitemap XML to this file instead of stdout}
        {--base-url= : Public URL the sitemap files are served from, used by the index when the set is split}';

    /**
     * How much of the byte budget the envelope may take.
     *
     * The budget is checked against the entries alone, because they are the part that grows.
     * The reserve covers the XML declaration, the opening <urlset> with both namespaces and
     * the closing tag -- about 190 bytes -- with room left over, so a document can never
     * cross the limit by the width of its own wrapper.
     */
    private const int ENVELOPE_RESERVE = 1024;

    /**
     * How many rows are read, and have their slugs and tokens loaded, in one round.
     *
     * Small enough that one round of models and slug rows is a modest amount of memory, large
     * enough that the queries per round stop mattering against the rows they serve.
     */
    private const int ROWS_PER_ROUND = 500;

    /** @var string */
    protected $description = 'Generate an XML sitemap (with hreflang alternates) for the registered sluggable models.';

    /**
     * Records the resolver could not address, by class.
     *
     * @var array<class-string, int>
     */
    private array $unaddressed = [];

    /**
     * Files written under a temporary name this run, each with the name it is published as.
     *
     * @var list<array{string, string}>
     */
    private array $staged = [];

    public function handle(): int
    {
        if (! Container::getInstance()->bound(PolyslugUrlResolver::class)) {
            $this->error('Bind '.PolyslugUrlResolver::class.' to generate a sitemap.');

            return self::FAILURE;
        }

        $resolver = Container::getInstance()->make(PolyslugUrlResolver::class);
        $config = Container::getInstance()->make(ConfigRepository::class);
        $types = $config->get('polyslug.sitemap.types', []);

        $maxUrls = $this->ceiling($config->get('polyslug.sitemap.max_urls'), 50_000);
        $maxBytes = $this->ceiling($config->get('polyslug.sitemap.max_bytes'), 50 * 1024 * 1024);

        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        try {
            return $this->generate($resolver, is_array($types) ? $types : [], $maxUrls, $maxBytes, $path);
        } finally {
            // A run that refused or threw published nothing, and the files it staged go. After a
            // successful run there are none left.
            $this->discardStaged();
        }
    }

    /**
     * @param  array<mixed>  $types
     */
    private function generate(PolyslugUrlResolver $resolver, array $types, int $maxUrls, int $maxBytes, ?string $path): int
    {
        // A part is flushed the moment it is full, so peak memory is ONE part rather than the
        // whole document. Writing to stdout has nowhere to flush to, so that path keeps the
        // single-document behavior and says so if the result is over a ceiling.
        $parts = [];
        $buffer = [];
        $bytes = 0;
        $written = 0;

        // Read before anything is replaced: the index this run overwrites is the only record of
        // the parts an earlier run wrote beside it.
        $previous = $path === null ? [] : $this->partsNamedBy($path);

        foreach ($types as $class) {
            if (! is_string($class)) {
                continue;
            }
            if (! is_a($class, Model::class, true)) {
                continue;
            }
            if (! is_a($class, Sluggable::class, true)) {
                continue;
            }
            // Stream rows so a giant table never loads into memory at once, and through the
            // model's own gate so a row it hides is not announced (see rows()).
            //
            // The class was checked against Sluggable above, so every row this loop sees is one;
            // the `instanceof` narrows the type for what follows.
            foreach ($this->rows($class) as $model) {
                if ($model instanceof Sluggable) {
                    foreach ($this->entriesFor($model, $resolver) as $entry) {
                        $size = strlen($entry) + 1;

                        // Checked BEFORE the entry is added, and only when the buffer already
                        // holds something: a single entry larger than the whole budget must
                        // still be written somewhere rather than flushed into an empty file.
                        if ($buffer !== [] && $path !== null
                            && (count($buffer) >= $maxUrls || $bytes + $size > $maxBytes - self::ENVELOPE_RESERVE)) {
                            $parts[] = $this->writePart($path, count($parts) + 1, $buffer);
                            $written += count($buffer);
                            $buffer = [];
                            $bytes = 0;
                        }

                        $buffer[] = $entry;
                        $bytes += $size;
                    }
                }
            }
        }

        $written += count($buffer);

        if ($path === null) {
            $this->line($this->render($buffer));
            $this->reportUnaddressed();

            if (count($buffer) > $maxUrls || $bytes > $maxBytes - self::ENVELOPE_RESERVE) {
                $this->warn('This sitemap is past the protocol limit of '.$maxUrls.' URLs or '
                    .$maxBytes.' bytes. Pass --path so it can be split across an index.');
            }

            return self::SUCCESS;
        }

        // Nothing was ever flushed, so everything still fits one file and the output is exactly
        // what it was before splitting existed: one document at --path, no index.
        if ($parts === []) {
            $this->stage($path, $this->render($buffer));
            $this->publishStaged();
            $this->removeParts($previous, []);
            $this->info($written.' URL(s) written to ['.$path.'].');
            $this->reportUnaddressed();

            return self::SUCCESS;
        }

        $base = $this->baseUrl();

        if ($base === null) {
            $this->error('This sitemap needs '.(count($parts) + 1).' files, and an index has to name each one by '
                .'absolute URL. Set app.url or pass --base-url.');

            return self::FAILURE;
        }

        $parts[] = $this->writePart($path, count($parts) + 1, $buffer);

        $this->stage($path, $this->renderIndex($base, $parts));
        $this->publishStaged();
        $this->removeParts($previous, array_map(fn (string $file): string => $this->besidePath($path, $file), $parts));
        $this->info($written.' URL(s) written across '.count($parts).' file(s), indexed by ['.$path.'].');
        $this->reportUnaddressed();

        return self::SUCCESS;
    }

    /**
     * The rows of one configured type, through the model's own resolution gate.
     *
     * Route binding and every resolution path go through polyslugResolveQuery(), so a row the
     * gate hides answers 404 at its address. Listing it anyway submits an address that does not
     * exist, and publishes a slug built from a title nobody has released. A crawler is an
     * anonymous requester and so is this command: a gate that reads a session or a tenant sees
     * here what a crawler would see.
     *
     * method_exists() rather than a contract method, for the reason polyslugLastModified() gives
     * further down: the gate lives on HasPolyslug, so an application implementing Sluggable by
     * hand keeps its whole table instead of failing to load.
     *
     * Read in rounds, and each round arrives with its slug rows and its tokens already loaded:
     * the `slugs` relation is eager-loaded with the round, and polyslugPreload() fetches the
     * round's tokens in one query. Every entry reads both, so without them a record cost about
     * five slug queries and a token query of its own, and a run over a million rows was six
     * million queries. A model implementing Sluggable by hand has neither the relation nor the
     * preload, and is read in rounds all the same.
     *
     * @param  class-string<Model>  $class
     * @return iterable<int, Model>
     */
    private function rows(string $class): iterable
    {
        $model = new $class;
        $gated = method_exists($model, 'polyslugResolveQuery') ? $model->polyslugResolveQuery($model->newQuery()) : null;
        $query = $gated instanceof Builder ? $gated : $model->newQuery();

        if (method_exists($model, 'polyslugPreload')) {
            $query->with('slugs');
        }

        // reorder() because a resolution gate may sort its query, and lazyById() keeps a foreign
        // order in front of the key it pages by. Each round would then be sorted by the gate's
        // column while the cursor moves by the key, and rows would be skipped or listed twice.
        // The sitemap needs every row once, in any order.
        foreach ($query->reorder()->lazyById(self::ROWS_PER_ROUND)->chunk(self::ROWS_PER_ROUND) as $round) {
            if (method_exists($model, 'polyslugPreload')) {
                $model->polyslugPreload($round);
            }

            yield from $round->values();
        }
    }

    /**
     * Name every type whose records the resolver threw on, and how many that was.
     *
     * The run succeeds either way -- a sitemap missing one type is worth more than no sitemap.
     * But a skip nobody is told about is how a configured type quietly stops being announced,
     * so the count goes out on every path that writes a document.
     */
    private function reportUnaddressed(): void
    {
        foreach ($this->unaddressed as $class => $count) {
            $this->warn(sprintf(
                '%s: %d record(s) skipped — the bound %s threw instead of returning a URL. '
                .'Remove the type from polyslug.sitemap.types, or implement canAddress() on the '
                .'resolver to say so without throwing.',
                $class,
                $count,
                class_basename(PolyslugUrlResolver::class),
            ));
        }
    }

    /**
     * Whether the bound resolver says it can address this record.
     *
     * Through method_exists() rather than a contract method: declaring `canAddress()` on
     * PolyslugUrlResolver would break every implementation that already exists, and this is the
     * additive shape the package uses elsewhere for exactly that reason. A resolver that says
     * nothing keeps its current behavior.
     *
     * A declared refusal is SILENT, unlike a throw. Naming a type as unaddressable is a
     * decision somebody made, and reporting it every run would train the reader to scroll past
     * the line that matters.
     */
    private function canAddress(Sluggable $model, PolyslugUrlResolver $resolver): bool
    {
        return ! method_exists($resolver, 'canAddress') || $resolver->canAddress($model) === true;
    }

    /**
     * A configured ceiling, or the protocol's own when the value is not a usable number.
     *
     * A zero or negative ceiling would flush after every entry and never terminate usefully, so
     * it is treated the same as an absent one rather than obeyed.
     */
    private function ceiling(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * The public URL the sitemap files are served from, without a trailing slash.
     */
    private function baseUrl(): ?string
    {
        $option = $this->option('base-url');

        if (is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }

        $configured = Container::getInstance()->make(ConfigRepository::class)->get('app.url');

        return is_string($configured) && $configured !== '' ? rtrim($configured, '/') : null;
    }

    /**
     * Write one numbered part beside --path and return its filename.
     *
     * `public/sitemap.xml` yields `public/sitemap-1.xml`, `public/sitemap-2.xml`, and so on --
     * beside the index rather than under it, because that is where the index's own relative
     * position lets a single base URL address both.
     *
     * @param  list<string>  $entries
     */
    private function writePart(string $path, int $number, array $entries): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME).'-'.$number;
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $file = $name.($extension === '' ? '' : '.'.$extension);

        $this->stage($this->besidePath($path, $file), $this->render($entries));

        return $file;
    }

    /**
     * A file name in the directory --path is in, the way the parts are written.
     */
    private function besidePath(string $path, string $file): string
    {
        $directory = dirname($path);

        return ($directory === '.' ? '' : $directory.'/').$file;
    }

    /**
     * The parts the index at --path names, as files beside it: the parts an earlier run wrote.
     *
     * Only a `<loc>` whose file name follows this command's own scheme for that path counts, so
     * an index somebody else wrote there, or a part it names under another name, gives nothing to
     * remove. A file at --path that is no index names no parts.
     *
     * @return list<string>
     */
    private function partsNamedBy(string $path): array
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (! str_contains($contents, '<sitemapindex')) {
            return [];
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $scheme = '/^'.preg_quote(pathinfo($path, PATHINFO_FILENAME), '/').'-\d+'
            .($extension === '' ? '' : '\.'.preg_quote($extension, '/')).'$/';

        preg_match_all('#<loc>([^<]*)</loc>#', $contents, $locations);

        $parts = [];

        foreach ($locations[1] as $location) {
            $file = basename((string) parse_url(htmlspecialchars_decode($location, ENT_QUOTES | ENT_XML1), PHP_URL_PATH));

            if (preg_match($scheme, $file) === 1) {
                $parts[] = $this->besidePath($path, $file);
            }
        }

        return $parts;
    }

    /**
     * Remove the parts an earlier run wrote that this run no longer does.
     *
     * Called once every file of the run is in place: a part the replaced index named and the new
     * set does not would otherwise stay public with the addresses of that earlier run. A part
     * that is already gone is left as it is.
     *
     * @param  list<string>  $previous
     * @param  list<string>  $current
     */
    private function removeParts(array $previous, array $current): void
    {
        foreach (array_diff($previous, $current) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Write a file under a temporary name beside its target, for publishStaged() to publish.
     *
     * The target is left alone until the run has produced every file, so a run that fails
     * part-way leaves the last good sitemap where it was. Writing in place replaced each file the
     * moment it was written: a run that ended early had already published the parts it reached.
     */
    private function stage(string $file, string $contents): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($temporary, $contents);

        $this->staged[] = [$temporary, $file];
    }

    /**
     * Publish every staged file under its own name, in the order it was staged: the parts
     * before the index that names them.
     *
     * rename() replaces a file in one step, so a crawler reads the old document or the new one
     * and never part of either. The permissions of a file being replaced carry over, as they
     * did when it was overwritten in place.
     */
    private function publishStaged(): void
    {
        foreach ($this->staged as [$temporary, $file]) {
            if (is_file($file)) {
                chmod($temporary, fileperms($file) & 0o777);
            }

            rename($temporary, $file);
        }

        $this->staged = [];
    }

    /**
     * Remove the files a run staged and did not publish.
     */
    private function discardStaged(): void
    {
        foreach ($this->staged as [$temporary]) {
            unlink($temporary);
        }

        $this->staged = [];
    }

    /**
     * Every address this record is served at, one `<url>` block each.
     *
     * ONE BLOCK PER LOCALE, not one per record, and the difference is the whole point of the
     * method. A `<url>` element is what sitemaps.org counts as a submitted address; an
     * `<xhtml:link>` inside it is an annotation ABOUT that address, not a submission of its
     * own. So a record served at /en/x and /de/x needs two blocks, each carrying the complete
     * alternate set including a reference to itself -- which is also what Google asks for.
     *
     * This used to emit a single block whose <loc> was the x-default address, with the other
     * locales appearing only as annotations. Measured with locales en, de and pt_BR: one
     * block, and the German and Brazilian addresses were never a <loc> at all. At N locales
     * that leaves (N-1)/N of the addresses unsubmitted.
     *
     * @return list<string>
     */
    private function entriesFor(Sluggable $model, PolyslugUrlResolver $resolver): array
    {
        // The same precedence the canonical middleware applies, in the same order: a gone
        // record answers 410, a superseded one whose successor the gate lets through answers
        // 301, and neither is an address to submit. The successor chain is the middleware's
        // own, so a successor the requester may not see, or a chain that comes back to the
        // record, leaves the record listed: that request is served, not redirected.
        if ($model->polyslugIsGone()) {
            return [];
        }

        if (SuccessorChain::lastVisible($model) instanceof Sluggable) {
            return [];
        }

        // THROUGH hreflangLinks(), not a second loop over the same locales. This method has
        // always carried the sentence "the sitemap and the hreflang set in the page head must
        // not be able to disagree about which addresses a record has" — and that is only true
        // when one of them computes the answer and the other reads it. The loop that used to
        // stand here matched the locale SET and still diverged on both things it did itself:
        // <loc> took $locales[0], the alphabetically first locale, while the head announces
        // x-default (the fallback locale); and no x-default alternate was emitted at all, so
        // the same package answered one question two ways. Measured before the change, with
        // locales [de, en] and fallback en: <loc> said /de/, x-default said /en/.
        // A RESOLVER THAT CANNOT ADDRESS THIS RECORD IS NOT A FAILED RUN. `url()` returns a
        // string, so an implementation with nothing to return has only an exception to reach
        // for -- and this command walks every configured type, so one throw used to end the
        // whole document. `polyslug.sitemap.types` is configuration filtered on Model and
        // Sluggable, and neither says anything about whether a type is routed: a model that
        // never had a route, or one whose route was renamed while the config stayed, is
        // ordinary rather than exotic.
        //
        // The failure direction made it worse. A type that can never be addressed would end
        // every run red, and a run that ends red does not replace the file it was going to
        // write, so the previous sitemap stays where it is and ages silently -- which from the
        // outside is indistinguishable from a sitemap being kept up to date.
        //
        // ONLY THE RESOLVER IS ASKED, and only about addressing. hreflangLinks() reads the
        // package's own tables as well, and the catch used to stand around the whole call: a
        // database that failed during a run emptied the sitemap, the run exited 0, and the
        // warning blamed the resolver. A database error says nothing about whether a record can
        // be addressed, wherever it is raised, so it ends the run. That run is transient and
        // red, and it publishes nothing, because the files are replaced only once every one of
        // them is written.
        if (! $this->canAddress($model, $resolver)) {
            return [];
        }

        $refused = false;

        $urls = $model->hreflangLinks(function (string $locale, string $routeKey) use ($model, $resolver, &$refused): string {
            try {
                return $resolver->url($model, $locale);
            } catch (Throwable $exception) {
                if ($exception instanceof QueryException) {
                    throw $exception;
                }

                $refused = true;

                return '';
            }
        });

        if ($refused) {
            // Counted rather than swallowed. The run continues, and the operator is told at
            // the end which types were dropped and how many records that was -- silence here
            // would trade a loud failure for a quiet one, which is the worse of the two.
            $class = $model::class;
            $this->unaddressed[$class] = ($this->unaddressed[$class] ?? 0) + 1;

            return [];
        }

        if ($urls === []) {
            return [];
        }

        // Built once and shared by every block: the alternate set is a property of the record,
        // not of the address, and reciprocity is exactly what breaks when each block computes
        // its own. Every block therefore carries the same set, self-reference included.
        $links = '';

        foreach ($urls as $hreflang => $url) {
            $links .= sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', e(Polyslug::hreflangCode($hreflang)), e($url));
        }

        // <lastmod> goes between <loc> and the alternates: sitemaps.org defines <url> as an
        // ordered sequence of loc, lastmod, changefreq, priority, and the xhtml alternates come
        // from another namespace entirely. DATE_ATOM is the W3C Datetime the protocol asks for.
        //
        // method_exists() rather than a contract method, the same no-break story the head
        // bridge tells about polyslugRobotsDirective(): polyslugLastModified() lives on
        // HasPolyslug, so an application implementing Sluggable by hand keeps emitting no
        // <lastmod> instead of failing to load.
        $modified = method_exists($model, 'polyslugLastModified') ? $model->polyslugLastModified() : null;
        $lastmod = $modified instanceof DateTimeInterface
            ? '<lastmod>'.e($modified->format(DATE_ATOM)).'</lastmod>'
            : '';

        $blocks = [];

        foreach ($urls as $hreflang => $url) {
            // x-default is skipped as a <loc>, not as an alternate: hreflangLinks() adds it as a
            // second key over an address that is already in the set, so submitting it as well
            // would put the fallback locale's URL in the document twice.
            if ($hreflang === 'x-default') {
                continue;
            }

            $blocks[] = '<url><loc>'.e($url).'</loc>'.$lastmod.$links.'</url>';
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $parts  file names, relative to the index
     */
    private function renderIndex(string $base, array $parts): string
    {
        $entries = array_map(
            fn (string $file): string => '<sitemap><loc>'.e($base.'/'.$file).'</loc></sitemap>',
            $parts,
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $entries)."\n"
            .'</sitemapindex>'."\n";
    }

    /**
     * @param  list<string>  $entries
     */
    private function render(array $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            .implode("\n", $entries)."\n"
            .'</urlset>'."\n";
    }
}
