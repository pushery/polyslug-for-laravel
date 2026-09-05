<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Override;
use Polyslug\Contracts\BulkIdentityEncoder;
use Polyslug\Contracts\StoresTokensPerRecord;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Support\SequentialTokenScheme;
use Polyslug\Support\TokenAlphabet;
use Polyslug\Support\TokenStore;

/**
 * The shortest URL a record can have: tokens are handed out in order — `0`, `1`, … `z`,
 * then `00` — and a width grows only once it is used up. What a link shortener wants.
 *
 * IT IS PREDICTABLE ON PURPOSE, and that is the entire trade. The token after `k3f8` is
 * `k3f9`, so anyone can walk the whole set, and the token reports both how many records
 * exist and roughly when this one was created. On public content nobody is trying to hide,
 * that costs nothing and buys the shortest link there is. On anything the URL alone
 * protects it is the wrong encoder, and no minimum length changes that — starting at four
 * characters moves where the counting begins, it does not scatter what follows.
 * {@see RandomTokenEncoder} is the one that makes a URL unguessable.
 *
 * Like the random encoder it STORES the mapping (polyslug_tokens), so the token is stable
 * per record, deleting a record never hands its token to another, and switching to this
 * encoder over a table full of random tokens starts counting past them instead of fighting
 * them — every existing URL keeps resolving.
 *
 * Configure the starting width and the alphabet with `polyslug.sequential_token`, or per
 * model with `#[Polyslug(encoderOptions: ['length' => …, 'alphabet' => …])]`.
 */
final readonly class SequentialTokenEncoder implements BulkIdentityEncoder, StoresTokensPerRecord
{
    /** Counting starts at the first token there is, so the first record gets a one-character URL. */
    public const int DEFAULT_LENGTH = 1;

    private TokenStore $store;

    private TokenScheme $tokenScheme;

    public function __construct(int $length = self::DEFAULT_LENGTH, ?TokenAlphabet $alphabet = null)
    {
        $this->tokenScheme = new SequentialTokenScheme($length, $alphabet);
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
}
