<?php

declare(strict_types=1);

namespace Polyslug\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;
use Polyslug\Polyslug;

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

    /** @var string */
    protected $description = 'Generate an XML sitemap (with hreflang alternates) for the registered sluggable models.';

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

        // A part is flushed the moment it is full, so peak memory is ONE part rather than the
        // whole document. Writing to stdout has nowhere to flush to, so that path keeps the
        // single-document behavior and says so if the result is over a ceiling.
        $parts = [];
        $buffer = [];
        $bytes = 0;
        $written = 0;

        foreach (is_array($types) ? $types : [] as $class) {
            if (! is_string($class)) {
                continue;
            }
            if (! is_a($class, Model::class, true)) {
                continue;
            }
            if (! is_a($class, Sluggable::class, true)) {
                continue;
            }
            // Stream rows so a giant table never loads into memory at once.
            //
            // A positive branch rather than a `! instanceof` with a `continue`, for the reason
            // TokenStore gives about its own: the class was already checked against Sluggable
            // above, so every row this loop sees is one. The `continue` was a statement no run
            // can execute -- the coverage floor said so -- and it reads to the next person like
            // a case that happens.
            foreach ($class::query()->lazyById() as $model) {
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

            if (count($buffer) > $maxUrls || $bytes > $maxBytes - self::ENVELOPE_RESERVE) {
                $this->warn('This sitemap is past the protocol limit of '.$maxUrls.' URLs or '
                    .$maxBytes.' bytes. Pass --path so it can be split across an index.');
            }

            return self::SUCCESS;
        }

        // Nothing was ever flushed, so everything still fits one file and the output is exactly
        // what it was before splitting existed: one document at --path, no index.
        if ($parts === []) {
            file_put_contents($path, $this->render($buffer));
            $this->info($written.' URL(s) written to ['.$path.'].');

            return self::SUCCESS;
        }

        $base = $this->baseUrl();

        if ($base === null) {
            $this->error('This sitemap needs '.(count($parts) + 1).' files, and an index has to name each one by '
                .'absolute URL. Set app.url or pass --base-url.');

            return self::FAILURE;
        }

        $parts[] = $this->writePart($path, count($parts) + 1, $buffer);

        file_put_contents($path, $this->renderIndex($base, $parts));
        $this->info($written.' URL(s) written across '.count($parts).' file(s), indexed by ['.$path.'].');

        return self::SUCCESS;
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
        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME).'-'.$number;
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $file = $name.($extension === '' ? '' : '.'.$extension);

        file_put_contents(($directory === '.' ? '' : $directory.'/').$file, $this->render($entries));

        return $file;
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
        // 301, and neither is an address to submit. The successor is resolved rather than
        // merely checked, as the middleware does, so a successor the requester may not see
        // leaves the record listed — that request is served, not redirected.
        if ($model->polyslugIsGone()) {
            return [];
        }

        $successor = $model->polyslugSupersededBy();

        if ($successor instanceof Sluggable && $successor->polyslugResolveSelf() instanceof Sluggable) {
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
        $urls = $model->hreflangLinks(
            fn (string $locale, string $routeKey): string => $resolver->url($model, $locale),
        );

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
