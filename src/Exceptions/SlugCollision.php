<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when `unique: false` is set but the generated slug already belongs to another
 * model in the same (type, locale, scope). `unique: false` deliberately drops the numeric
 * suffix that would otherwise disambiguate a collision, so the slug has to be collision-free
 * within its scope — and this one is not.
 *
 * Unlike CouldNotWriteSlug (a transient write conflict, worth retrying), this is a
 * deterministic configuration/data collision: retrying cannot help. Choose a distinct
 * source, add a `scope` that separates the records, or drop `unique: false` so Polyslug
 * appends a `-2` suffix.
 */
final class SlugCollision extends RuntimeException
{
    public function __construct(
        public readonly string $sluggableType,
        public readonly string $slug,
        public readonly string $locale,
        public readonly string $scope,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                '%s is already in use for [%s] in this uniqueness scope (locale [%s]); `unique: false` drops the numeric suffix that would disambiguate a collision. Choose a distinct source, add a scope that separates the records, or drop `unique: false`.',
                $slug === '' ? 'An empty (id-only) slug' : sprintf('The slug [%s]', $slug),
                $sluggableType,
                $locale,
            ),
            0,
            $previous,
        );
    }
}
