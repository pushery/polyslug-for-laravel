<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Closure;
use Polyslug\Support\TokenAlphabet;

/**
 * Decides what a NEWLY issued token looks like — the one thing that differs between an
 * unguessable URL and a counted one.
 *
 * It is deliberately not an IdentityEncoder. An encoder answers "which record is this
 * token" and must therefore be able to decode; a scheme only ever proposes a candidate,
 * and the store it is proposing to is what remembers the answer. That split is why the
 * same two schemes serve both stored-token encoders and the `/go/{token}` short link.
 */
interface TokenScheme
{
    /**
     * A token to try on the given 0-based claim attempt.
     *
     * The caller re-enters this method only when the previous candidate lost to another
     * TOKEN — a lost race for the same key returns the winner's token instead of looping —
     * so `$attempt` measures how contended this scheme's current output length is, and an
     * implementation may use it to yield to a longer token.
     *
     * @param  Closure(): int  $issued  How many tokens the store has already handed out, as
     *                                  a lower bound. A LAZY closure rather than a number,
     *                                  because answering it costs a query and a random
     *                                  scheme never needs to ask.
     */
    public function draw(int $attempt, Closure $issued): string;

    /**
     * The shortest token this scheme will produce, for diagnostics that want to say how
     * full a token space is before it fills.
     */
    public function length(): int;

    /** The alphabet this scheme draws from, for the same diagnostics. */
    public function alphabet(): TokenAlphabet;
}
