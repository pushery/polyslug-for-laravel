<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use InvalidArgumentException;
use Override;
use Polyslug\Contracts\IdentityEncoder;
use Sqids\Sqids;

/**
 * Encodes integer keys with Sqids.
 *
 * This is OBFUSCATION, NOT SECURITY: a Sqids token is reversible by anyone who knows
 * the alphabet, and it still leaks row count and growth. Use a leak-free encoder
 * (UUID, ULID, random token) when count or creation time must not be inferable.
 */
final readonly class SqidsEncoder implements IdentityEncoder
{
    private Sqids $sqids;

    public function __construct(?string $alphabet = null, int $minLength = 0)
    {
        $this->sqids = $alphabet === null
            ? new Sqids(minLength: $minLength)
            : new Sqids($alphabet, $minLength);
    }

    #[Override]
    public function encode(int|string $id): string
    {
        if (is_string($id)) {
            if (! ctype_digit($id)) {
                throw new InvalidArgumentException("The Sqids encoder requires integer keys; got [{$id}].");
            }

            // ctype_digit() says nothing about MAGNITUDE, and (int) SATURATES at PHP_INT_MAX
            // rather than failing -- so a twenty-digit key and PHP_INT_MAX itself would encode
            // to the same token. Two records, one URL, and whichever loses decodes back to a
            // key it does not own. Refused here rather than downstream, because Sqids' own
            // range error names neither the key nor this encoder.
            //
            // Compared after stripping leading zeros, which is the one difference the cast is
            // allowed to make: `007` is the same key as `7`, and an arm pins that.
            $magnitude = ltrim($id, '0');

            if (($magnitude === '' ? '0' : $magnitude) !== (string) (int) $id) {
                throw new InvalidArgumentException("The Sqids encoder requires a key inside the integer range; got [{$id}].");
            }

            $id = (int) $id;
        }

        if ($id < 0) {
            throw new InvalidArgumentException('The Sqids encoder requires non-negative integer keys.');
        }

        return $this->sqids->encode([$id]);
    }

    #[Override]
    public function decode(string $token): ?int
    {
        $numbers = array_values($this->sqids->decode($token));

        if (count($numbers) !== 1) {
            return null;
        }

        $id = $numbers[0];

        // Reject non-canonical aliases: a crafted string can decode to a valid number
        // without being that number's canonical encoding. Requiring a round-trip keeps
        // exactly one token per resource, so the canonical URL is unambiguous.
        if ($this->sqids->encode([$id]) !== $token) {
            return null;
        }

        return $id;
    }
}
