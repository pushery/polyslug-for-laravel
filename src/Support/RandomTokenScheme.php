<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Closure;
use InvalidArgumentException;
use Override;
use Polyslug\Contracts\TokenScheme;

/**
 * Unguessable tokens: every one is drawn at random, so a URL says nothing about the record
 * behind it — not its key, not when it was created, not how many others exist.
 *
 * The default scheme, and the right one whenever the URL itself is part of the access story
 * (a share link, an unlisted page, anything enumeration-sensitive).
 */
final readonly class RandomTokenScheme implements TokenScheme
{
    /**
     * The length used when nothing says otherwise.
     *
     * Sixteen characters out of a 36-character alphabet is roughly 2^82 tokens — far past
     * anything a guesser reaches, which is why it is what an application that never thinks
     * about the question gets.
     */
    public const int DEFAULT_LENGTH = 16;

    /** The longest token the `token` columns (plain string columns) can hold. */
    public const int MAX_LENGTH = 255;

    /**
     * Failed draws tolerated at one length before the next draw gets a character more.
     *
     * THIS IS WHAT MAKES A SHORT LENGTH A REAL OPTION RATHER THAN A TRAP. Two characters is
     * 1,296 tokens; a thousand records in, every draw is a coin flip, and at 1,296 the space
     * is simply gone. Without this, that ends as a CouldNotIssueToken thrown from encode() —
     * which runs while a URL is being RENDERED, so an application that chose a short length
     * gets a 500 on a GET, months later, on whichever record happened to be next.
     *
     * So a configured length is a FLOOR, not a ceiling: a length that keeps colliding yields
     * to one character more, which is 36x the space. Three draws is the tolerance — a length
     * a tenth full is left alone 99.9% of the time, one nine-tenths full gives way about
     * three times in four, which is where it should give way.
     *
     * Refusing instead, and telling the operator to raise the length, was rejected because
     * the moment it would speak is the moment nothing can be done quickly: the configured
     * length is what EXISTING tokens were drawn at, so raising it helps new records only,
     * and until that deploy lands every new record is a 500.
     */
    private const int DRAWS_PER_LENGTH = 3;

    private TokenAlphabet $characters;

    public function __construct(
        private int $length = self::DEFAULT_LENGTH,
        ?TokenAlphabet $alphabet = null,
    ) {
        // Refused rather than clamped. A clamp turns a typo into a silently different token
        // space — and for a value whose whole purpose is to say how hard a token is to guess,
        // quietly substituting another number is the one failure mode that must not survive.
        if ($this->length < 1 || $this->length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A random token length must be between 1 and %d; got [%d].',
                self::MAX_LENGTH,
                $this->length,
            ));
        }

        $this->characters = $alphabet ?? new TokenAlphabet;
    }

    #[Override]
    public function draw(int $attempt, Closure $issued): string
    {
        $length = min(self::MAX_LENGTH, $this->length + intdiv(max(0, $attempt), self::DRAWS_PER_LENGTH));
        $size = $this->characters->size();
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int is the CSPRNG. A token drawn from a predictable sequence would make
            // every URL predictable, which is the single property this scheme exists to give.
            $token .= $this->characters->at(random_int(0, $size - 1));
        }

        return $token;
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
