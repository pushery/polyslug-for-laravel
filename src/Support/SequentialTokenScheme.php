<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Closure;
use InvalidArgumentException;
use Override;
use Polyslug\Contracts\TokenScheme;

/**
 * Counted tokens: the shortest token that has not been handed out yet — `1`, `2`, … `z`,
 * then `10`, `11`, and so on, one character wider only once a width is used up.
 *
 * This is what a link shortener wants, and it is the exact opposite trade from
 * {@see RandomTokenScheme}. It produces the shortest URL that can exist for a given number
 * of records, and in exchange the URL is COMPLETELY PREDICTABLE: the next token follows
 * from the last one, so anybody can walk the whole set, and the token itself reports how
 * many records there are and roughly when this one was created.
 *
 * That is a legitimate choice for public, non-enumeration-sensitive content, which is why
 * the package offers it. It is the wrong choice for anything the URL alone protects, and
 * it is not the default for that reason.
 *
 * A MINIMUM LENGTH does not fix that, and should not be mistaken for doing so. Starting at
 * four characters means the counting starts at the first four-character token instead of
 * the first one-character token; it does not scatter the tokens, and `k3f8` is still
 * followed by `k3f9`.
 */
final readonly class SequentialTokenScheme implements TokenScheme
{
    /**
     * The longest minimum length that can be counted to.
     *
     * Skipping every shorter token means counting past all of them, and a 36-character
     * alphabet exhausts a 64-bit integer at 12 characters. The limit is stated here rather
     * than discovered as an overflow, and TokenAlphabet::offsetForLength() refuses anything
     * an alphabet cannot represent even when it is under this number.
     */
    public const int MAX_LENGTH = 12;

    /** Counting starts at the first token there is, so the first record gets a one-character URL. */
    public const int DEFAULT_LENGTH = 1;

    private TokenAlphabet $characters;

    private int $offset;

    public function __construct(
        private int $length = self::DEFAULT_LENGTH,
        ?TokenAlphabet $alphabet = null,
    ) {
        if ($this->length < 1 || $this->length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A sequential token length must be between 1 and %d; got [%d].',
                self::MAX_LENGTH,
                $this->length,
            ));
        }

        $this->characters = $alphabet ?? new TokenAlphabet;
        $this->offset = $this->characters->offsetForLength($this->length);
    }

    /**
     * The next token after the ones already issued, plus one per attempt already lost.
     *
     * `$issued` is a LOWER BOUND, not a cursor, and the difference is what makes this safe
     * to run concurrently and safe to switch on midway through a table's life. The store
     * answers it from the highest row id it has, which counts rows written by any scheme —
     * so turning this on over a table full of random tokens starts counting past them
     * instead of colliding with them for the rest of the day. Where the bound is wrong, the
     * unique index says so and `$attempt` walks forward until it is right.
     */
    #[Override]
    public function draw(int $attempt, Closure $issued): string
    {
        return $this->characters->encode($this->offset + $issued() + 1 + max(0, $attempt));
    }

    #[Override]
    public function length(): int
    {
        return $this->length;
    }

    #[Override]
    public function alphabet(): TokenAlphabet
    {
        return $this->characters;
    }
}
