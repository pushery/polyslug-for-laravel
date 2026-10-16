<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use Polyslug\Contracts\SitemapStorage;
use RuntimeException;

/**
 * Thrown when a sitemap cannot do what it is asked: write an index without a root to name its
 * parts by, rebuild a source it does not have, hold two sources of one name, or render an index
 * from a storage that keeps no record of its parts.
 *
 * Nothing is published when it is thrown during a write: the storage drops the write.
 */
final class CouldNotBuildSitemap extends RuntimeException
{
    /**
     * @param  int  $files  how many files a sitemap without a root would have needed, 0 for every
     *                      other refusal
     */
    private function __construct(string $message, public readonly int $files = 0)
    {
        parent::__construct($message);
    }

    public static function withoutRoot(int $files): self
    {
        return new self(
            'This sitemap needs '.$files.' files, and an index has to name each one by absolute URL. '
            .'Give the sitemap the root its parts are served under.',
            $files,
        );
    }

    public static function unknownSource(string $source): self
    {
        return new self('This sitemap has no source named ['.$source.'].');
    }

    public static function sourceNamedTwice(string $source): self
    {
        return new self(
            'Two sources of this sitemap are named ['.$source.'], and the parts of one would be '
            .'written over the parts of the other. Give each source a name of its own.'
        );
    }

    public static function keepsNoParts(SitemapStorage $storage): self
    {
        return new self(
            $storage::class.' keeps no record of its parts, so there is no index to render from it: '
            .'the files it writes are the sitemap, and they are served as they are.'
        );
    }
}
