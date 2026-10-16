<?php

declare(strict_types=1);

namespace Polyslug\Support;

use InvalidArgumentException;

/**
 * Tokens taken from the end of a longer id, for an identity column (`#[Polyslug(identity: ...)]`).
 *
 * A record projected from another system carries that system's id, and the end of it makes a
 * short token that stays the same however often the record is written again: the last ten
 * characters of a ULID are random. Two ids can end alike, so apart() settles that the same way
 * whatever order the records arrive in. The id that sorts first keeps the short token, and every
 * other one answers under its whole id; for a ULID or a UUIDv7 the id that sorts first is the
 * older one. An application stores the result in the identity column when it writes the
 * records, and their URLs resolve by that column.
 */
final class TailTokens
{
    /**
     * An id the way a token writes it: lower case, with its letters and digits only.
     */
    public static function whole(string $id): string
    {
        return mb_strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $id));
    }

    /**
     * The last `$length` characters of an id the way a token writes it, or all of it when it is
     * shorter. Empty for an id without a letter or a digit, which gives no token.
     */
    public static function of(string $id, int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException("A token takes at least one character from the end of an id, and {$length} is fewer.");
        }

        return mb_substr(self::whole($id), -$length);
    }

    /**
     * The token of each id of a group, keyed as the group is.
     *
     * Of the ids that end alike, the one that sorts first keeps the short token, and every other
     * one answers under its whole id. Where that is taken as well, by an id that appears twice or
     * by a short id that is its own end, the token carries `-2`, then `-3`, in the order the ids
     * sort, and among equal ids in the order of their keys. Hand over every id that can end like
     * the ones being written, the stored ones included, since the answer depends on all of them.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string>  $ids
     * @return array<TKey, string>
     */
    public static function apart(array $ids, int $length): array
    {
        // Keyed by the end of the ids, and PHP turns an end of digits alone into an integer key,
        // so the end is read back as a string below.
        /** @var array<array-key, list<TKey>> $groups */
        $groups = [];

        foreach ($ids as $key => $id) {
            $groups[self::of($id, $length)][] = $key;
        }

        $tokens = [];

        foreach ($groups as $tail => $keys) {
            // usort() is stable, so ids that are equal keep the order of their keys.
            usort($keys, static fn (int|string $one, int|string $other): int => strcmp(self::whole($ids[$one]), self::whole($ids[$other])));

            $taken = [];

            foreach ($keys as $index => $key) {
                $wanted = $index === 0 ? (string) $tail : self::whole($ids[$key]);
                $token = $wanted;

                for ($suffix = 2; in_array($token, $taken, true); $suffix++) {
                    $token = $wanted.'-'.$suffix;
                }

                $taken[] = $token;
                $tokens[$key] = $token;
            }
        }

        return $tokens;
    }
}
