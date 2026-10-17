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
 *
 * A token moves when the ids that end like it change, so an address can still carry an id after
 * the column holds another token. idsIn() reads the ids out of such an address, and tokensFor()
 * names the tokens apart() can have given them, which is how a model that names the column of
 * the id (`identityOf`) still resolves it.
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
     * one answers under its whole id. Two entries can carry the same id, as another system moves
     * it from one record to another: a second id of each, such as that system's own id of the
     * record, decides which one sorts first, and the later one answers under its whole id with its
     * second id after a hyphen, `{id}-{second id}`. Where that is taken as well, by entries whose
     * second ids are equal too, by an entry without a second id or by a short id that is its own
     * end, the token carries `-2`, then `-3`, in the order the entries sort, and among equal ones
     * in the order of their keys. Hand over every id that can end like the ones being written, the
     * stored ones included, since the answer depends on all of them.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string>  $ids
     * @param  array<TKey, string>  $secondIds  the second id of an entry, keyed as its id is; an entry without one sorts before the entries with one
     * @return array<TKey, string>
     */
    public static function apart(array $ids, int $length, array $secondIds = []): array
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
            // usort() is stable, so entries whose ids and second ids are equal keep the order of
            // their keys.
            usort($keys, static function (int|string $one, int|string $other) use ($ids, $secondIds): int {
                $order = strcmp(self::whole($ids[$one]), self::whole($ids[$other]));

                return $order !== 0 ? $order : strcmp(self::whole($secondIds[$one] ?? ''), self::whole($secondIds[$other] ?? ''));
            });

            $taken = [];

            foreach ($keys as $index => $key) {
                $wanted = $index === 0 ? (string) $tail : self::whole($ids[$key]);
                $second = self::whole($secondIds[$key] ?? '');

                if ($second !== '' && in_array($wanted, $taken, true)) {
                    $wanted .= '-'.$second;
                }

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

    /**
     * The ids an address token carries, the way apart() writes them: the whole id, and the second
     * id after a hyphen where there is one. A number after the second id is left out, since it only
     * tells an entry apart for as long as the entries before it are there. Null for a token
     * apart() does not write, such as one with a capital letter or an underscore in it.
     *
     * @return array{0: string, 1: string|null}|null
     */
    public static function idsIn(string $token): ?array
    {
        if (preg_match('/^([a-z0-9]+)(?:-([a-z0-9]+))?(?:-[0-9]+)?$/', $token, $parts) !== 1) {
            return null;
        }

        return [$parts[1], $parts[2] ?? null];
    }

    /**
     * Every token apart() can have given an entry with this id and second id, the shortest first.
     *
     * The entry that sorts first in its group answers under the end of its id, and how long that
     * end is, the application chose, so each end is one of them, the whole id last. The whole id
     * with the second id after it follows, where a second id is given.
     *
     * @return list<string>
     */
    public static function tokensFor(string $id, ?string $secondId = null): array
    {
        $whole = self::whole($id);
        $size = mb_strlen($whole);
        $tokens = [];

        for ($length = 1; $length <= $size; $length++) {
            $tokens[] = mb_substr($whole, -$length);
        }

        $second = self::whole($secondId ?? '');

        if ($whole !== '' && $second !== '') {
            $tokens[] = $whole.'-'.$second;
        }

        return $tokens;
    }
}
