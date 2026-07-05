<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Override;
use Polyslug\Contracts\IdentityEncoder;

/**
 * Pass-through encoder for models keyed by a ULID.
 *
 * The 26-character Crockford-base32 key is compact and URL-safe, so it travels
 * verbatim; decoding validates the ULID shape and rejects anything else (→ 404).
 * A ULID is lexicographically sortable and embeds its creation timestamp, so it
 * leaks creation time — use it only where that is acceptable.
 */
final class UlidEncoder implements IdentityEncoder
{
    #[Override]
    public function encode(int|string $id): string
    {
        $id = (string) $id;

        if (! Str::isUlid($id)) {
            throw new InvalidArgumentException("The ULID encoder requires a ULID key; got [{$id}].");
        }

        return $id;
    }

    #[Override]
    public function decode(string $token): ?string
    {
        return Str::isUlid($token) ? $token : null;
    }
}
