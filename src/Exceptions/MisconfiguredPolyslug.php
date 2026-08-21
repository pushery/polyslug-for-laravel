<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;

/**
 * Thrown when a #[Polyslug] attribute (or a PolyslugConfig from a polyslug() override)
 * combines mutually exclusive options.
 *
 * Currently: `idLess: true` with `unique: false`. An idLess URL is the slug alone — there
 * is no encoded id to disambiguate — so the slug MUST stay unique to resolve to one model.
 * `unique: false` (which lets records share a slug) only makes sense for a non-idLess model,
 * whose id carries identity.
 */
final class MisconfiguredPolyslug extends RuntimeException
{
    public static function reclaimRequiresIdLess(): self
    {
        return new self(
            'A #[Polyslug] model cannot set `reclaim: true` without `idLess: true`: on a model whose '
            .'URL carries an encoded id, a retired slug is already free to reuse, so reclaim would '
            .'change nothing. Add `idLess: true` if the URL is the slug alone, or drop `reclaim`.'
        );
    }

    public static function idLessRequiresUnique(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `idLess: true` with `unique: false`: an idLess URL is '
            .'the slug alone, so the slug must stay unique to resolve. Drop `unique: false`, or drop '
            .'`idLess: true` so the encoded id can disambiguate records that share a slug.'
        );
    }
}
