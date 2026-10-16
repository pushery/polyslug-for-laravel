<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use Closure;
use Polyslug\Contracts\SitemapStorage;

/**
 * A sitemap as files: its parts beside a path and the index at the path itself, or the one part
 * alone at the path while the sitemap has no more than one.
 *
 * `public/sitemap.xml` keeps the parts of a source named `sitemap` as `public/sitemap-1.xml`,
 * `public/sitemap-2.xml`, beside the index, where one base URL serves both; a path without an
 * extension gives parts without one.
 *
 * Every file is written under a temporary name and renamed into place once the whole write is in,
 * the parts before the index that names them, so a crawler reads the old document or the new one and
 * never part of either, and a write that fails publishes nothing. A replaced file keeps its
 * permissions. A part the replaced index named and the new set no longer holds is removed once the
 * new files are in place; a file that index did not name stays, whatever it is called.
 *
 * Files keep no record of where each part starts, so every rebuild writes the sitemap whole.
 */
final class FileSitemapStorage implements SitemapStorage
{
    /**
     * Files written under a temporary name in this write, each with the name it is published as.
     *
     * @var list<array{string, string}>
     */
    private array $staged = [];

    /** @var list<SitemapPart> */
    private array $written = [];

    /** @var list<string> */
    private array $sources = [];

    /**
     * The file names the index this write replaces names.
     *
     * @var list<string>
     */
    private array $previous = [];

    public function __construct(private readonly string $path) {}

    public function parts(): ?array
    {
        return null;
    }

    public function body(string $source, int $number): ?string
    {
        $file = $this->besidePath($this->name($source, $number));

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function name(string $source, int $number): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);

        return $source.'-'.$number.($extension === '' ? '' : '.'.$extension);
    }

    public function begin(): void
    {
        $this->discard();
        $this->written = [];
        $this->sources = [];

        // Read before anything is replaced: the index this write overwrites is the only record of
        // the parts an earlier write put beside it.
        $this->previous = $this->namedByTheIndex();
    }

    public function put(SitemapPart $part): void
    {
        $this->stage($this->besidePath($this->name($part->source, $part->number)), (string) $part->body);
        $this->written[] = $part->withoutBody();
    }

    public function trim(string $source, int $parts): void
    {
        // Every write of files is whole, so the parts it no longer writes are known once it commits,
        // where the new set is complete. What this records is which sources the write settled.
        $this->sources[] = $source;
    }

    public function commit(Closure $entry): void
    {
        try {
            $current = [];

            if (count($this->written) === 1) {
                // One part is the whole sitemap, and it goes to the path itself, with no index.
                $staged = $this->staged;
                $this->staged = [];

                foreach ($staged as [$temporary]) {
                    $this->staged[] = [$temporary, $this->path];
                }
            } else {
                foreach ($this->written as $part) {
                    $current[] = $this->besidePath($this->name($part->source, $part->number));
                }

                $this->stage($this->path, $entry($this->written));
            }

            $this->publish();

            foreach (array_diff($this->ownParts(), $current) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } finally {
            $this->discard();
        }
    }

    public function rollBack(): void
    {
        $this->discard();
    }

    /**
     * A file name in the directory the path is in.
     */
    private function besidePath(string $file): string
    {
        $directory = dirname($this->path);

        return ($directory === '.' ? '' : $directory.'/').$file;
    }

    /**
     * The file names the index at the path names, read from its `<loc>` elements; none where the
     * file at the path is no index.
     *
     * @return list<string>
     */
    private function namedByTheIndex(): array
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        if (! str_contains($contents, '<sitemapindex')) {
            return [];
        }

        preg_match_all('#<loc>([^<]*)</loc>#', $contents, $locations);

        return array_map(
            static fn (string $location): string => basename((string) parse_url(htmlspecialchars_decode($location, ENT_QUOTES | ENT_XML1), PHP_URL_PATH)),
            $locations[1],
        );
    }

    /**
     * The files of the replaced index that are parts of a source this write settled, named the way
     * this storage names its parts: only those can be parts an earlier write put here. A location
     * keeps its query string and its escaping out of the file name.
     *
     * @return list<string>
     */
    private function ownParts(): array
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $own = [];

        foreach ($this->sources as $source) {
            $scheme = '/^'.preg_quote($source, '/').'-\d+'.($extension === '' ? '' : '\.'.preg_quote($extension, '/')).'$/';

            foreach ($this->previous as $file) {
                if (preg_match($scheme, $file) === 1) {
                    $own[] = $this->besidePath($file);
                }
            }
        }

        return $own;
    }

    /**
     * Writes a file under a temporary name beside its target, for publish() to rename into place.
     */
    private function stage(string $file, string $contents): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($temporary, $contents);

        $this->staged[] = [$temporary, $file];
    }

    /**
     * Publishes every staged file under its own name, in the order it was staged: the parts before
     * the index that names them. rename() replaces a file in one step, and the permissions of a file
     * being replaced carry over.
     */
    private function publish(): void
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
     * Removes the files a write staged and did not publish.
     */
    private function discard(): void
    {
        foreach ($this->staged as [$temporary]) {
            unlink($temporary);
        }

        $this->staged = [];
    }
}
