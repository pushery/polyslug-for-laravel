<?php

declare(strict_types=1);

namespace Polyslug\Support;

use InvalidArgumentException;

/**
 * The character set a generated token is built from, and the counting system that goes
 * with it.
 *
 * Its own type rather than a bare string, because every rule about what makes a legal
 * alphabet has to hold for both schemes and both stores — and because the bijective
 * numbering below is the part that is easy to get subtly wrong.
 */
final readonly class TokenAlphabet
{
    /**
     * Digits first, then lowercase letters: ordinary base-36, and the order that makes a
     * counted token read the way a reader expects (`7` before `a`, `zz` before `100`).
     *
     * Lowercase only, and that is the one opinion baked into the DEFAULT rather than into
     * the type: a URL is compared case-sensitively while people copy it out of print, chat
     * and speech, so distinguishing `aB` from `Ab` buys entropy and pays for it in support
     * tickets. An application that wants the entropy back passes its own alphabet.
     */
    public const string DEFAULT = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * The alphabet as characters, indexed from 0.
     *
     * @var non-empty-list<non-empty-string>
     */
    private array $characters;

    public function __construct(public string $alphabet = self::DEFAULT)
    {
        $characters = mb_str_split($this->alphabet);

        // Two characters is the floor because base-1 does not count: every "digit" would be
        // the same character, so the n-th token would be n copies of it and a token space
        // would grow by one character per record.
        if (count($characters) < 2) {
            throw new InvalidArgumentException('A token alphabet needs at least 2 characters; got '.count($characters).'.');
        }

        // A repeated character makes the numbering ambiguous — two different numbers encode
        // to the same token, so a counted scheme hands the same token to two records and the
        // second one loses to the unique index forever.
        if (count(array_unique($characters)) !== count($characters)) {
            throw new InvalidArgumentException('A token alphabet cannot repeat a character.');
        }

        // A token is a whole path segment. Anything outside the unreserved set of RFC 3986
        // either has to be percent-encoded (so the URL is no longer the token) or changes
        // what the path means — a `/` splits the segment, a `?` or `#` ends the path.
        if (preg_match('/^[A-Za-z0-9._~-]+$/D', $this->alphabet) !== 1) {
            throw new InvalidArgumentException(
                'A token alphabet may only use URL-unreserved characters (A-Z a-z 0-9 - . _ ~); got ['.$this->alphabet.'].'
            );
        }

        $this->characters = $characters;
    }

    public function size(): int
    {
        return count($this->characters);
    }

    public function at(int $index): string
    {
        return $this->characters[$index];
    }

    /**
     * The n-th token, counting from 1, in BIJECTIVE base-N.
     *
     * Bijective rather than ordinary positional notation, and that is the whole reason this
     * method is not two lines of base_convert(). Ordinary notation cannot write a leading
     * zero-digit, so `0a` — and every other token whose first character is the alphabet's
     * first — is a token no counter ever reaches: at base 36 that discards about 3% of every
     * length, and the discarded ones are precisely the shortest-looking. Bijective numbering
     * walks every length completely before it grows: 1..N are the one-character tokens,
     * N+1..N+N^2 the two-character ones, and so on with no gaps and no repeats.
     *
     * (This is the same counting system as spreadsheet columns: A..Z, then AA — never A0.)
     */
    public function encode(int $n): string
    {
        $size = $this->size();
        $token = '';

        while ($n > 0) {
            // The decrement before the modulo is what makes it bijective: it shifts the
            // remainder range from 0..N-1 onto the *previous* place value, so the last
            // character of a place is a full digit instead of a carry into a leading zero.
            $n--;
            $token = $this->characters[$n % $size].$token;
            $n = intdiv($n, $size);
        }

        return $token;
    }

    /**
     * How many tokens exist that are SHORTER than the given length — the number to start
     * counting from when a scheme is told never to go below it.
     *
     * @throws InvalidArgumentException when the answer would exceed what an int can hold,
     *                                  which is the honest failure: past that point the
     *                                  counter cannot be represented, let alone reached.
     */
    public function offsetForLength(int $length): int
    {
        $size = $this->size();
        $offset = 0;
        $place = 1;

        for ($i = 1; $i < $length; $i++) {
            if ($place > intdiv(PHP_INT_MAX, $size)) {
                throw new InvalidArgumentException(sprintf(
                    'A minimum token length of %d is out of range for a %d-character alphabet: the '
                    .'counter that skips every shorter token no longer fits in an integer. Use a '
                    .'random token scheme for lengths that large — counting to them is not possible.',
                    $length,
                    $size,
                ));
            }

            $place *= $size;
            $offset += $place;
        }

        return $offset;
    }

    /** How many distinct tokens exist at exactly the given length — N^length, as a float past 2^63. */
    public function spaceFor(int $length): float
    {
        // Parenthesised because ** binds tighter than a cast: without them the exponent is
        // computed first and the cast describes a value that already exists.
        return ((float) $this->size()) ** $length;
    }
}
