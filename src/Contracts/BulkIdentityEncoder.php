<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

/**
 * An encoder that can issue tokens for many keys at once.
 *
 * DELIBERATELY A SECOND INTERFACE rather than a method on IdentityEncoder. Adding a
 * method to a shipped interface breaks every consumer that implements it — and
 * implementing it is a documented capability here (`#[Polyslug(encoder: ...)]`), not a
 * hypothetical. So this is opt-in: an encoder that does not implement it keeps working
 * unchanged, and callers fall back to encode() per key.
 *
 * Only a STORE-BACKED encoder has anything to gain. Sqids, UUID, ULID and the raw key
 * derive their token from the key alone and never touch the database, so implementing
 * this on them would add a second code path that saves nothing and could drift from the
 * first.
 */
interface BulkIdentityEncoder extends IdentityEncoder
{
    /**
     * Encode many keys, in as few round trips as the implementation can manage.
     *
     * The result MUST be identical to calling encode() on each key in turn — same
     * tokens, same collision semantics, same exception on exhaustion. This is an
     * optimization of the round trips, never of the guarantees.
     *
     * @param  list<int|string>  $ids
     * @return array<string, string> token keyed by the STRING form of each id, so a
     *                               caller can look up `(string) $model->getKey()`
     *                               without worrying whether the key was an int
     */
    public function encodeMany(array $ids): array;
}
