<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Closure;
use Polyslug\Sitemaps\SitemapPart;

/**
 * Where the parts of a sitemap are kept: files beside a path, a table, or a store of your own.
 *
 * A sitemap writes in one go: begin(), then put() for every part it writes and trim() for every
 * source whose parts it settled, then commit() or rollBack(). What a write puts is served together
 * once it commits, and nothing of it after a rollback.
 */
interface SitemapStorage
{
    /**
     * Every part the storage holds, in the order of their sources and numbers, each without its
     * body; null for a storage that keeps no record of its parts, whose sitemap every rebuild then
     * writes whole.
     *
     * Read inside a write, it is what that write plans from, so a storage that several processes
     * write makes the second write wait here until the first one has committed.
     *
     * @return list<SitemapPart>|null
     */
    public function parts(): ?array;

    /**
     * The XML of one part, null where the storage holds none.
     */
    public function body(string $source, int $number): ?string;

    /**
     * The name a part is served under, relative to the sitemap's root.
     */
    public function name(string $source, int $number): string;

    /**
     * Opens a write.
     */
    public function begin(): void;

    /**
     * Writes a part in place of the one with its source and number.
     */
    public function put(SitemapPart $part): void;

    /**
     * Settles a source at the number of parts given: every part of it numbered above goes, all of
     * them for 0.
     */
    public function trim(string $source, int $parts): void;

    /**
     * Publishes the write.
     *
     * @param  Closure(list<SitemapPart>): string  $entry  the document a sitemap of the parts given
     *                                                     is entered by: the index naming them, or an
     *                                                     empty `<urlset>` for none. For a storage
     *                                                     that keeps that document itself.
     */
    public function commit(Closure $entry): void;

    /**
     * Drops the write.
     */
    public function rollBack(): void;
}
