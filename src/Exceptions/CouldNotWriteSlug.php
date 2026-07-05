<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a slug could not be written after repeated write conflicts — a
 * concurrent writer kept claiming the generated slug (or the one-current-row) up to
 * the configured polyslug.write.max_attempts. The underlying database error is
 * available via getPrevious().
 */
final class CouldNotWriteSlug extends RuntimeException
{
    public function __construct(string $sluggableType, string $source, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Could not write a slug for [%s] from source [%s] after repeated write conflicts.', $sluggableType, $source),
            0,
            $previous,
        );
    }
}
