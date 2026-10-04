<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Override;
use Polyslug\Contracts\BulkIdentityDecoder;
use Polyslug\Contracts\BulkIdentityEncoder;
use Polyslug\Contracts\StoresTokensPerRecord;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Support\RandomTokenScheme;
use Polyslug\Support\TokenAlphabet;
use Polyslug\Support\TokenStore;

/**
 * Leak-safe identity: each key maps to an unguessable random token kept in the
 * polyslug_tokens table. The URL token reveals nothing about the key — no row count,
 * order, or value — so it suits enumeration-sensitive, integer-keyed tables. The
 * token is stable per key (one row), so each record keeps a single canonical URL.
 *
 * NOTE — encode() WRITES on first use. Rendering a link issues an INSERT the first
 * time a given key is encoded, and never again afterwards. That rules this encoder
 * out on a read-only replica connection, and it makes the first render of a batch of
 * new records the moment of peak write contention. Both are inherent to storing the
 * mapping; the retry loop in {@see TokenStore} is what makes them safe rather than fatal.
 *
 * TOKEN LENGTH is a parameter, not a constant, because the two things it trades off are
 * the consumer's to weigh and not this package's: URL length against how many tokens a
 * guesser must try. A share link that is the only thing between a visitor and the content
 * wants length; a token behind an authorization check, meant only to keep the URL out of
 * the way, does not. Set it with `polyslug.random_token.length`, or per model with
 * `#[Polyslug(encoderOptions: ['length' => …])]`. It is a FLOOR: a length whose space fills
 * up yields to one character more rather than failing to issue a URL.
 *
 * CHANGING IT DOES NOT BREAK EXISTING URLS. A token is looked up in the table, never
 * recomputed from the key, so tokens issued at the old length keep resolving unchanged and
 * only records encoded from here on get the new one. The table legitimately holds a mix.
 *
 * For the opposite trade — the shortest possible URL, at the cost of a completely
 * predictable one — see {@see SequentialTokenEncoder}.
 */
final readonly class RandomTokenEncoder implements BulkIdentityDecoder, BulkIdentityEncoder, StoresTokensPerRecord
{
    /** @see RandomTokenScheme::DEFAULT_LENGTH */
    public const int DEFAULT_LENGTH = RandomTokenScheme::DEFAULT_LENGTH;

    private TokenStore $store;

    private TokenScheme $tokenScheme;

    public function __construct(int $length = self::DEFAULT_LENGTH, ?TokenAlphabet $alphabet = null)
    {
        $this->tokenScheme = new RandomTokenScheme($length, $alphabet);
        $this->store = new TokenStore($this->tokenScheme);
    }

    /**
     * The scheme this encoder issues tokens from.
     *
     * Exposed for diagnostics — `polyslug:doctor` reports how full a token space is, and it
     * cannot say that without knowing the alphabet the space is counted in.
     */
    public function scheme(): TokenScheme
    {
        return $this->tokenScheme;
    }

    /**
     * The UNTYPED lane, kept for the inherited contract.
     *
     * Polyslug itself always calls encodeWithin(); this is what a consumer calling the
     * encoder directly gets, and what tokens issued before tokens had owners live in.
     */
    #[Override]
    public function encode(int|string $id): string
    {
        return $this->store->tokenFor($id);
    }

    #[Override]
    public function encodeWithin(string $type, int|string $id): string
    {
        return $this->store->tokenFor($id, $type);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    #[Override]
    public function encodeMany(array $ids): array
    {
        return $this->store->tokensFor($ids);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    #[Override]
    public function encodeManyWithin(string $type, array $ids): array
    {
        return $this->store->tokensFor($ids, $type);
    }

    #[Override]
    public function decode(string $token): int|string|null
    {
        return $this->store->keyFor($token);
    }

    #[Override]
    public function decodeWithin(string $type, string $token): int|string|null
    {
        return $this->store->keyFor($token, $type);
    }

    #[Override]
    public function decodeMany(array $tokens): array
    {
        return $this->store->keysFor($tokens);
    }

    #[Override]
    public function decodeManyWithin(string $type, array $tokens): array
    {
        return $this->store->keysFor($tokens, $type);
    }
}
