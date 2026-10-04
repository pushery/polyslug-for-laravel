<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

/**
 * An encoder that can turn many tokens back into keys at once.
 *
 * The counterpart of BulkIdentityEncoder, and a separate interface for the same reason: a method
 * added to a shipped interface breaks every encoder that implements it. An encoder that does not
 * implement this keeps working, and callers decode one token at a time.
 *
 * Only a store-backed encoder has anything to gain. Sqids, UUID, ULID and the raw key read the
 * key out of the token itself and never touch the database.
 */
interface BulkIdentityDecoder extends IdentityEncoder
{
    /**
     * Decode many tokens of the untyped lane, in as few round trips as the implementation can
     * manage.
     *
     * The result MUST be what decode() answers for each token in turn. A token decode() answers
     * with null is absent from the result.
     *
     * @param  list<string>  $tokens
     * @return array<array-key, int|string> key by token; PHP keeps a numeric token as an int key,
     *                                      and a lookup by the token string finds it either way
     */
    public function decodeMany(array $tokens): array;

    /**
     * Decode many tokens WITHIN one morph type, the way decodeWithin() decodes one: a token that
     * belongs to another type is absent from the result, as is a token nothing holds.
     *
     * @param  list<string>  $tokens
     * @return array<array-key, int|string> key by token
     */
    public function decodeManyWithin(string $type, array $tokens): array;
}
