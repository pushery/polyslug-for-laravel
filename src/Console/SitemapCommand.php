<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Exceptions\CouldNotBuildSitemap;
use Polyslug\Sitemaps\FileSitemapStorage;
use Polyslug\Sitemaps\Sitemap;
use Polyslug\Sitemaps\SluggableSource;

/**
 * The sitemap of the models in `polyslug.sitemap.types`, as files or on standard output.
 *
 * With --path it is a Sitemap of one SluggableSource, named after the file, in a
 * FileSitemapStorage: the parts it writes are the parts the same sitemap writes into any other
 * storage. Without --path the document goes to standard output in one piece, whatever its size.
 */
final class SitemapCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:sitemap
        {--path= : Write the sitemap XML to this file instead of stdout}
        {--base-url= : Public URL the sitemap files are served from, used by the index when the set is split}';

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
        $types = is_array($types) ? $types : [];

        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            return $this->toStandardOutput(new SluggableSource('sitemap', $types, $resolver), $config);
        }

        $source = new SluggableSource(pathinfo($path, PATHINFO_FILENAME), $types, $resolver);

        try {
            $parts = new Sitemap([$source], new FileSitemapStorage($path), $this->baseUrl())->build();
        } catch (CouldNotBuildSitemap $refusal) {
            // A sitemap of one source in files refuses for one reason: it needs an index, and the
            // index has to name each part by absolute URL. Nothing was published.
            $this->error('This sitemap needs '.$refusal->files.' files, and an index has to name each one by '
                .'absolute URL. Set app.url or pass --base-url.');

            return self::FAILURE;
        }

        $written = 0;

        foreach ($parts as $part) {
            $written += $part->urls;
        }

        $this->info(count($parts) > 1
            ? $written.' URL(s) written across '.count($parts).' file(s), indexed by ['.$path.'].'
            : $written.' URL(s) written to ['.$path.'].');
        $this->reportUnaddressed($source);

        return self::SUCCESS;
    }

    /**
     * The whole sitemap as one document on standard output, with a warning where it is past a
     * ceiling: there is nowhere to split it to.
     */
    private function toStandardOutput(SluggableSource $source, ConfigRepository $config): int
    {
        $entries = [];
        $bytes = 0;

        foreach ($source->records() as $record) {
            foreach (Sitemap::entries($record) as $entry) {
                $entries[] = $entry;
                $bytes += strlen($entry) + 1;
            }
        }

        $this->line(Sitemap::urlset($entries));
        $this->reportUnaddressed($source);

        $maxUrls = $this->ceiling($config->get('polyslug.sitemap.max_urls'), 50_000);
        $maxBytes = $this->ceiling($config->get('polyslug.sitemap.max_bytes'), 50 * 1024 * 1024);

        if (count($entries) > $maxUrls || $bytes > $maxBytes - Sitemap::ENVELOPE_RESERVE) {
            $this->warn('This sitemap is past the protocol limit of '.$maxUrls.' URLs or '
                .$maxBytes.' bytes. Pass --path so it can be split across an index.');
        }

        return self::SUCCESS;
    }

    /**
     * Name every type whose records the resolver threw on, and how many that was.
     *
     * The run succeeds either way, since a sitemap missing one type is worth more than no sitemap.
     * But a skip nobody is told about is how a configured type quietly stops being announced, so the
     * count goes out on every path that writes a document.
     */
    private function reportUnaddressed(SluggableSource $source): void
    {
        foreach ($source->unaddressed() as $class => $count) {
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
     * A configured ceiling, or the protocol's own where the value is no positive whole number.
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
}
