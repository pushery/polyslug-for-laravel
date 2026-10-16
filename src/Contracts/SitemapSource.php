<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Polyslug\Sitemaps\SitemapRecord;

/**
 * The records a sitemap lists, each with the key that orders it and every address it is served at.
 *
 * A source orders its records by their keys, compared byte by byte, and reads any range of them:
 * the sitemap cuts the source into parts by those keys, and builds one part again by reading the
 * range of keys that part holds. A record's key never changes, so a part keeps holding it.
 *
 * Polyslug\Sitemaps\SluggableSource reads sluggable models. A source of your own reads anything
 * else, a read model with its own address scheme for one.
 */
interface SitemapSource
{
    /**
     * The name of the source, which names its parts: `pages` gives `pages-1.xml`, `pages-2.xml`.
     */
    public function name(): string;

    /**
     * The records with keys from $from, inclusive, up to $until, exclusive, in the byte order of
     * their keys. An empty $from starts at the first record, and a null $until runs to the last.
     *
     * @return iterable<int, SitemapRecord>
     */
    public function records(string $from = '', ?string $until = null): iterable;
}
