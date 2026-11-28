<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

/**
 * Where a cut of a source over several writes stands, as each call of Sitemap::recut() returns it
 * and the next call continues from it.
 *
 * It holds strings, a count and parts without their bodies, so a queued job can carry it to the job
 * of the next step.
 */
final readonly class SitemapRecut
{
    /**
     * @param  string  $source  the source the cut is of
     * @param  string|null  $next  the key the next part of the cut starts at, null once the cut is
     *                             done and its parts are served
     * @param  int  $parts  how many parts the cut has written so far, every part of the source once
     *                      it is done
     * @param  list<SitemapPart>  $written  the parts the call that returned this wrote, without their
     *                                      bodies
     */
    public function __construct(
        public string $source,
        public ?string $next,
        public int $parts,
        public array $written = [],
    ) {}

    /**
     * Whether the cut is done: its parts are served, and there is no call left to make.
     */
    public function done(): bool
    {
        return $this->next === null;
    }
}
