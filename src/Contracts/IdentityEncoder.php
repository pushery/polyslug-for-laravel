<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use InvalidArgumentException;

interface IdentityEncoder
{
    /**
     * Encode a model key into an opaque, URL-safe token.
     *
     * @throws InvalidArgumentException when the key is not supported by the encoder
     */
    public function encode(int|string $id): string;

    /**
     * Decode a token back to a model key, or null when the token is invalid or
     * non-canonical. Callers MUST treat null as "not found" (→ 404) and never as a
     * cue to guess a nearby key — a leaked neighbor must not silently resolve.
     */
    public function decode(string $token): int|string|null;
}
