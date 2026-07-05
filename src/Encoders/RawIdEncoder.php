<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Override;
use Polyslug\Contracts\IdentityEncoder;

/**
 * Raw pass-through of a non-negative integer key — the key appears in the URL verbatim.
 *
 * This LEAKS the sequential primary key: row count, growth rate, ordering, and it
 * lets a visitor probe neighboring records by incrementing the number. It exists
 * for parity with the framework default and for internal tooling; never use it on a
 * public, enumeration-sensitive route. Non-numeric tokens decode to null (→ 404).
 */
final class RawIdEncoder implements IdentityEncoder
{
    #[Override]
    public function encode(int|string $id): string
    {
        return (string) $id;
    }

    #[Override]
    public function decode(string $token): ?int
    {
        if (! ctype_digit($token)) {
            return null;
        }

        $id = (int) $token;

        // Reject non-canonical aliases (leading zeros): the token must round-trip,
        // so each record has exactly one canonical URL — consistent with SqidsEncoder.
        return (string) $id === $token ? $id : null;
    }
}
