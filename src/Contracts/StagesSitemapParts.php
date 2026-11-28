<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Polyslug\Sitemaps\SitemapPart;

/**
 * A sitemap storage that holds a second set of parts for a source beside the parts it serves, so a
 * source can be cut anew over several writes: Sitemap::recut() stages the parts of the new cut a few
 * at a time, and its last write promotes them in place of the parts served until then.
 *
 * Every method is called inside a write, between begin() and commit(), and what it does is published
 * or dropped with that write. A staged part is served by nothing until it is promoted: parts() and
 * body() answer about the parts that are served.
 */
interface StagesSitemapParts
{
    /**
     * Writes a part into the staged set of its source, in place of a staged one with its number.
     */
    public function stage(SitemapPart $part): void;

    /**
     * The staged parts of a source, without their bodies, in the order of their numbers.
     *
     * @return list<SitemapPart>
     */
    public function staged(string $source): array;

    /**
     * Serves the staged parts of a source numbered up to the count given, in place of the parts it
     * served until now. Every other staged part of the source goes.
     */
    public function promote(string $source, int $parts): void;

    /**
     * Drops the staged parts of a source.
     */
    public function discard(string $source): void;
}
