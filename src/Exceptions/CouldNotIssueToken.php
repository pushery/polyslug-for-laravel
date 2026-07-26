<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when RandomTokenEncoder could not claim a token for a key after repeated
 * conflicts — concurrent writers kept winning the key, or (astronomically unlikely)
 * successive random draws kept colliding with existing tokens.
 *
 * Reaching this is not a race being lost, which the encoder recovers from by adopting
 * the winner's token; it is losing every attempt in a row. In practice that means the
 * polyslug_tokens table is being contended far beyond what URL rendering produces, or
 * something else is inserting into it.
 */
final class CouldNotIssueToken extends RuntimeException
{
    public function __construct(string $key, int $attempts, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Could not issue a random token for key [%s] after %d attempts.', $key, $attempts),
            0,
            $previous,
        );
    }
}
