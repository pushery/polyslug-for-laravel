<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;

final class SitemapCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:sitemap {--path= : Write the sitemap XML to this file instead of stdout}';

    /** @var string */
    protected $description = 'Generate an XML sitemap (with hreflang alternates) for the registered sluggable models.';

    public function handle(): int
    {
        if (! Container::getInstance()->bound(PolyslugUrlResolver::class)) {
            $this->error('Bind '.PolyslugUrlResolver::class.' to generate a sitemap.');

            return self::FAILURE;
        }

        $resolver = Container::getInstance()->make(PolyslugUrlResolver::class);
        $types = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.sitemap.types', []);
        $entries = [];

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
            foreach ($class::query()->lazyById() as $model) {
                if ($model instanceof Sluggable) {
                    $entry = $this->entry($model, $resolver);

                    if ($entry !== null) {
                        $entries[] = $entry;
                    }
                }
            }
        }

        $xml = $this->render($entries);
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $xml);
            $this->info(count($entries).' URL(s) written to ['.$path.'].');
        } else {
            $this->line($xml);
        }

        return self::SUCCESS;
    }

    private function entry(Sluggable $model, PolyslugUrlResolver $resolver): ?string
    {
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
            return null;
        }

        $links = '';

        foreach ($urls as $hreflang => $url) {
            $links .= sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', e($hreflang), e($url));
        }

        // x-default is the record's primary address by the only definition this package has,
        // and hreflangLinks() guarantees the key exists whenever the set is non-empty.
        return '<url><loc>'.e($urls['x-default']).'</loc>'.$links.'</url>';
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
