<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use DateTimeInterface;

/**
 * One record of a sitemap source: the key that orders it, every address it is served at, and when
 * its content last changed.
 */
final readonly class SitemapRecord
{
    /**
     * @param  string  $key  orders the record within its source, compared byte by byte, and never
     *                       changes
     * @param  array<string, string>  $alternates  every address of the record by the locale or
     *                                             `hreflang` code it is served under, `x-default`
     *                                             among them where the record has one
     * @param  DateTimeInterface|null  $lastModified  written as `<lastmod>` where given
     */
    public function __construct(
        public string $key,
        public array $alternates,
        public ?DateTimeInterface $lastModified = null,
    ) {}
}
