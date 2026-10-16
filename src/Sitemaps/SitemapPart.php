<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

/**
 * One part of a sitemap: the records of one source from the key the part starts at up to the key
 * the next part of that source starts at.
 */
final readonly class SitemapPart
{
    /**
     * @param  int  $number  counts the parts of the source from 1
     * @param  string|null  $startsAt  the key of the first record the part holds, empty for the
     *                                 first part of its source. Null where the keys of the source
     *                                 came out of byte order, which leaves the ranges unknown: the
     *                                 next rebuild of the source cuts it anew.
     * @param  int  $urls  the addresses the part names
     * @param  string|null  $lastModified  the latest `<lastmod>` of its records, as the part writes it
     * @param  string|null  $body  the XML, null in a list of parts read without it
     */
    public function __construct(
        public string $source,
        public int $number,
        public ?string $startsAt,
        public int $urls,
        public ?string $lastModified = null,
        public ?string $body = null,
    ) {}

    /**
     * The same part without its XML, which a list of parts does not carry.
     */
    public function withoutBody(): self
    {
        return new self($this->source, $this->number, $this->startsAt, $this->urls, $this->lastModified);
    }
}
