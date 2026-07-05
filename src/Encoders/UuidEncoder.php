<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Override;
use Polyslug\Contracts\IdentityEncoder;

/**
 * Pass-through encoder for models keyed by a UUID.
 *
 * The key is already opaque and URL-safe, so it travels verbatim; decoding only
 * validates the UUID shape and rejects anything else (→ 404) before a query runs.
 * Leaks neither row count nor ordering — but a UUIDv7/ordered UUID still encodes
 * creation time, so prefer UUIDv4 when that must stay private.
 */
final class UuidEncoder implements IdentityEncoder
{
    #[Override]
    public function encode(int|string $id): string
    {
        $id = (string) $id;

        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException("The UUID encoder requires a UUID key; got [{$id}].");
        }

        return $id;
    }

    #[Override]
    public function decode(string $token): ?string
    {
        return Str::isUuid($token) ? $token : null;
    }
}
