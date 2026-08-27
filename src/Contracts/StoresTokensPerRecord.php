<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

/**
 * An encoder whose token is STORED against a record rather than computed from its key.
 *
 * The distinction is not academic. A computed token — Sqids, a UUID, the raw key — is a
 * function of the key alone, so two models holding id 1 unavoidably produce the same token
 * and there is nothing an encoder could do about it. A STORED token is a row, and a row can
 * record who it belongs to. This interface is how an encoder says it can.
 *
 * Polyslug hands the morph type to any encoder that implements it, and each such encoder
 * keeps a token space per type: `Page#1` and `Wishlist#1` get different tokens, and one
 * model's URL no longer yields another model's URL for the same id.
 *
 * IT EXTENDS IdentityEncoder RATHER THAN REPLACING IT, so an encoder written against the
 * older contract keeps working untouched — it simply keeps one shared space, which is what
 * it always had. The inherited untyped methods stay meaningful here too: they address the
 * UNTYPED lane, which is where tokens issued before this contract existed live, and it is
 * what a legacy decoder reaches through.
 */
interface StoresTokensPerRecord extends IdentityEncoder
{
    /** The token for a record of this morph type, issued on first use. */
    public function encodeWithin(string $type, int|string $id): string;

    /**
     * The key this token belongs to WITHIN this morph type.
     *
     * Null when the token belongs to another type or to nothing — which makes a token
     * borrowed from a different model a clean 404 rather than a lookup against this model's
     * own ids.
     */
    public function decodeWithin(string $type, string $token): int|string|null;

    /**
     * One round trip for a whole batch of this morph type.
     *
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    public function encodeManyWithin(string $type, array $ids): array;
}
