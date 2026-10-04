<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a token could not be claimed for a key after repeated conflicts: concurrent
 * writers kept winning the key, or the drawn tokens kept colliding with existing ones. The
 * identity tokens (polyslug_tokens) and the short links (polyslug_short_links) both claim
 * this way, under the random and the counted scheme alike, and the message names the table.
 *
 * Reaching this is not a race being lost, which the claim recovers from by adopting the
 * winner's token; it is losing every attempt in a row. In practice that means the table is
 * being contended far beyond what URL rendering produces, or something else is inserting
 * into it.
 */
final class CouldNotIssueToken extends RuntimeException
{
    public function __construct(string $key, int $attempts, ?Throwable $previous = null, string $table = 'polyslug_tokens')
    {
        parent::__construct(
            sprintf('Could not issue a token for key [%s] in %s after %d attempts.', $key, $table, $attempts),
            0,
            $previous,
        );
    }
}
