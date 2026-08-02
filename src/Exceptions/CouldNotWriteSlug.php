<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a slug could not be written after repeated write conflicts — a
 * concurrent writer kept claiming the generated slug (or the one-current-row) up to
 * the configured polyslug.write.max_attempts. The model keeps whatever slug it had
 * before — RESTORED in place, not rolled back: the write path runs in a transaction that
 * always commits and puts the demoted row back itself, because a nested savepoint is
 * unreliable once DDL has implicitly committed an outer transaction.
 *
 * $previous is therefore normally null. A lost race is a return value here, not a throw —
 * insertOrIgnore reports zero affected rows rather than raising a unique-constraint
 * violation, which is what makes the retry portable across engines.
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
