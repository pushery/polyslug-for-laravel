<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Exceptions\CouldNotIssueToken;
use stdClass;

/**
 * The polyslug_tokens table: one stable token per model key, claimed race-safely.
 *
 * Split out of the encoders rather than duplicated across them, because everything hard
 * here is about CLAIMING — losing a race, telling which race was lost, and recovering
 * without an exception on a GET. Which token is proposed is the {@see TokenScheme}'s
 * business, and it is the only thing that differs between an unguessable URL and a
 * counted one.
 */
final class TokenStore
{
    /**
     * How many times a claim re-attempts before giving up.
     *
     * Every attempt loses only to a CONCURRENT writer or to a token that is already taken,
     * and each loss teaches the loop something: either the key is now claimed (adopt that
     * token and stop) or this candidate was taken (ask the scheme for the next one). Eight
     * rather than the write path's five, because a scheme that widens its output every three
     * lost draws needs room for two widenings — after which the space is 1,296x larger than
     * the one that was full, and a ninth attempt would be answering a question nobody asked.
     */
    private const int CLAIM_ATTEMPTS = 8;

    /**
     * The UNTYPED lane: the type a token carries when nobody named one.
     *
     * A column default rather than null, because nulls do not collide in a unique index on
     * any of the three engines — a nullable owner would let two rows hold the same key_value
     * and reintroduce, in this lane, the ambiguity the owner column removes.
     */
    public const string UNTYPED = '';

    /**
     * How many entries each memo holds before it lets go of its older half.
     *
     * The memos copy rows that do not change once claimed, so dropping an entry costs one
     * query the next time that key is asked for, never a wrong answer. Without a ceiling they
     * grew for the life of the process: a sitemap run over a large table, a queue worker or a
     * long-lived server kept every token it had ever seen, and the decode memo keeps misses as
     * well, so a stream of invented tokens could grow it without end.
     */
    private const int MEMO_LIMIT = 10_000;

    /**
     * How many keys or tokens one statement of a batch carries.
     *
     * Every statement of a batch binds one parameter per key, the insert five, and an engine
     * refuses a statement past its limit: 65,535 parameters on PostgreSQL and MySQL, 32,766 on
     * SQLite. A batch therefore runs in slices of this size, which keeps the widest statement
     * at 5,000 parameters.
     */
    private const int SLICE = 1_000;

    /** @var array<string, string> */
    private array $encoded = [];

    /** @var array<string, int|string|null> */
    private array $decoded = [];

    /**
     * @param  bool  $ownSpace  whether the space belongs to the models that configured it through
     *                          their encoderOptions, rather than being shared by every model; a
     *                          counted scheme then starts from the rows of the type it is asked for
     */
    public function __construct(
        private readonly TokenScheme $scheme,
        private readonly int $memoLimit = self::MEMO_LIMIT,
        private readonly bool $ownSpace = false,
    ) {}

    public function tokenFor(int|string $id, string $type = self::UNTYPED): string
    {
        $key = (string) $id;
        $memo = $type."\0".$key;

        if (isset($this->encoded[$memo])) {
            return $this->encoded[$memo];
        }

        $this->trim($this->encoded);

        // Read-then-write is a RACE, and this runs on the URL-render path: two requests
        // rendering the same never-before-encoded model both miss the SELECT and both
        // INSERT, so the loser takes a unique-constraint violation — a 500 on a GET,
        // intermittent and unreproducible after the fact. insertOrIgnore turns that loss
        // into a return value instead of an exception (a caught duplicate-key error inside an
        // application's own transaction leaves that transaction aborted on PostgreSQL unless a
        // savepoint is rolled back, and the slug write path avoids savepoint rollbacks for the
        // same reason), and the loser then adopts the winner's token: both requests emit the
        // same canonical URL, which is the correct outcome anyway.
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            // BOTH lanes in one statement. This runs while a URL is being rendered, so the
            // owner's row and the row this record may have left in the UNTYPED lane are asked
            // for together — reading them one after the other would put a second query on the
            // render path for every record's first encode, to answer a question that is almost
            // always "no".
            $lookup = DB::table('polyslug_tokens')
                ->where('key_value', $key)
                ->whereIn('key_type', array_unique([$type, self::UNTYPED]));

            // After a lost attempt the re-read must escape the transaction snapshot, or
            // it cannot see the winner. Under MySQL's default REPEATABLE READ a plain
            // SELECT keeps returning the snapshot taken at transaction start, so a caller
            // that encodes inside DB::transaction() would loop until exhaustion against a
            // row that demonstrably exists — the insert collides with it every time.
            // SELECT … FOR UPDATE reads the latest committed row instead. The first
            // attempt stays lock-free: the common case is an uncontended hit or a clean
            // miss, and taking a row lock for that would be pure cost.
            // (Postgres defaults to READ COMMITTED and never showed this — which is
            // precisely why the proof runs on both engines.)
            if ($attempt > 0) {
                $lookup->lockForUpdate();
            }

            [$existing, $orphan] = $this->splitLanes($lookup->get(['key_type', 'token']), $type);

            if ($existing !== null) {
                return $this->encoded[$memo] = $existing;
            }

            // Nothing under this owner, but a row in the UNTYPED lane belongs to this record:
            // a token issued before tokens had owners, which the upgrade migration could not
            // attribute because the record has no slug row to read the type from. Adopting it
            // is what keeps that record's published URL alive; only the caller knows who is
            // asking, which is why the migration leaves the row alone and this does not.
            if ($orphan !== null && $this->claimOrphan($key, $type)) {
                return $this->encoded[$memo] = $orphan;
            }

            $token = $this->scheme->draw($attempt, $this->lowerBound($type, $attempt));

            $inserted = DB::table('polyslug_tokens')->insertOrIgnore([
                'key_type' => $type,
                'key_value' => $key,
                'token' => $token,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($inserted > 0) {
                return $this->encoded[$memo] = $token;
            }

            // Zero rows means SOME unique index rejected this row, and the table carries
            // two: (key_type, key_value) (a concurrent writer claimed this record — the next
            // iteration's SELECT finds their token and returns it) and token (this candidate
            // was taken — the next iteration asks the scheme for another). Both recover by looping,
            // which is why the loop re-reads rather than assuming which one it hit;
            // telling them apart here would need the engine-specific error the ignore
            // just swallowed.
        }

        throw new CouldNotIssueToken($key, self::CLAIM_ATTEMPTS);
    }

    /**
     * One SELECT for every key, one INSERT for the ones that are missing, a thousand keys a
     * statement.
     *
     * This is what the default configuration reaches through, and it is the only shipped
     * encoder path that reads the database — so on a rendered list it was the last per-row
     * query left after the slug relation became eager-loadable.
     *
     * The single-key path is NOT bypassed, it is the fallback, and that is deliberate:
     * every guarantee tokenFor() makes about a lost race lives there. A key whose bulk
     * insert was ignored — because a concurrent writer claimed the key, or because a
     * candidate collided — is handed straight back to it, and it re-reads and either adopts
     * the winner's token or asks the scheme again. Reimplementing that recovery here would
     * mean two places to keep correct, and the second one only runs under concurrency,
     * where a mistake is least likely to be noticed.
     *
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    public function tokensFor(array $ids, string $type = self::UNTYPED): array
    {
        $keys = [];

        foreach ($ids as $id) {
            $key = (string) $id;

            // Deduplicated, because a caller passing the same key twice must not turn into
            // two rows racing each other in the same INSERT.
            $keys[$key] = true;
        }

        $memo = fn (string $key): string => $type."\0".$key;

        // Trimmed before the batch rather than during it: every decision below reads the
        // memo, and an entry dropped halfway through would send its key down the slow path.
        $this->trim($this->encoded);

        // Cast BACK to string, and this is not redundant. PHP normalizes a numeric string
        // array key to an int, so array_keys() on a map built from `(string) $id` hands back
        // ints for every numeric id — which is every default Eloquent key. The keys are
        // strings everywhere else in this class, so without this the memo closure below is
        // handed an int and dies on its own type hint.
        /** @var list<string> $wanted */
        $wanted = array_map(strval(...), array_keys($keys));
        $missing = array_values(array_filter($wanted, fn (string $key): bool => ! isset($this->encoded[$memo($key)])));

        $now = Carbon::now();
        $offset = 0;

        // Asked at most once for the whole batch, and only if the scheme asks at all: a random
        // scheme never opens the closure, and paying for a count it does not read would put a
        // query back into the one path that exists to remove them. Memoized across the slices
        // rather than re-read per slice, because the offset below walks the count forward over
        // every row this batch has drafted, written or not, so a fresh count after the first
        // slice would count those rows twice.
        $issued = null;
        $counted = function () use (&$issued, $type): int {
            return $issued ??= $this->ownSpace ? $this->issuedWithin($type) : $this->issued();
        };

        foreach (array_chunk($missing, self::SLICE) as $slice) {
            // BOTH lanes, exactly as the single-key path reads them, and for the same reason:
            // a record whose token predates owners must be ADOPTED rather than issued a second
            // one. Missing that here was not a slow path, it was a wrong one — a single
            // polyslugPreload() over such records would mint fresh tokens for all of them and
            // retire every URL they were published under, silently and in bulk.
            $rows = DB::table('polyslug_tokens')
                ->whereIn('key_type', array_unique([$type, self::UNTYPED]))
                ->whereIn('key_value', $slice)
                ->get(['key_type', 'key_value', 'token']);

            $orphans = [];

            foreach ($rows as $row) {
                $key = $this->columnString($row->key_value ?? null);
                $token = $this->columnString($row->token ?? null);

                if ($key !== null && $token !== null) {
                    if ($this->columnString($row->key_type ?? null) === $type) {
                        $this->encoded[$memo($key)] = $token;
                    } else {
                        // A LIST of pairs, not a map keyed by the key: PHP normalizes a
                        // numeric array key to an int, and every method here takes a string.
                        $orphans[] = [$key, $token];
                    }
                }
            }

            foreach ($orphans as [$key, $token]) {
                if (! isset($this->encoded[$memo($key)]) && $this->claimOrphan($key, $type)) {
                    $this->encoded[$memo($key)] = $token;
                }
            }

            $unclaimed = array_values(array_filter($slice, fn (string $key): bool => ! isset($this->encoded[$memo($key)])));

            if ($unclaimed !== []) {
                DB::table('polyslug_tokens')->insertOrIgnore(array_map(
                    // Each row gets its own candidate, and a COUNTED scheme needs them to
                    // differ: it answers from how many tokens existed when the batch began, so
                    // every row would otherwise be handed the same next number and all but one
                    // would be dropped by the unique index. The offset walks the count forward
                    // as if the earlier rows had landed, across the slices as well.
                    function (string $key) use ($now, $counted, $type, &$offset): array {
                        $token = $this->scheme->draw(0, static fn (): int => $counted() + $offset);
                        $offset++;

                        return [
                            'key_type' => $type,
                            'key_value' => $key,
                            'token' => $token,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    },
                    $unclaimed,
                ));

                // Read back rather than trusting the drafted tokens: insertOrIgnore reports
                // how many rows landed, not WHICH — so a row rejected by either unique index
                // is indistinguishable here from one that succeeded. The re-read settles it,
                // and whatever is still absent goes through tokenFor() and its retry loop.
                /** @var array<string, string> $claimed */
                $claimed = DB::table('polyslug_tokens')
                    ->where('key_type', $type)
                    ->whereIn('key_value', $unclaimed)
                    ->pluck('token', 'key_value')
                    ->all();

                foreach ($claimed as $key => $token) {
                    $this->encoded[$memo((string) $key)] = (string) $token;
                }
            }
        }

        $encoded = [];

        foreach ($wanted as $key) {
            // Whatever the batch could not settle — an ignored insert, an orphan waiting to be
            // adopted — falls through to the single-key path, which owns every guarantee about
            // a lost race and about the untyped lane.
            $encoded[$key] = $this->encoded[$memo($key)] ?? $this->tokenFor($key, $type);
        }

        return $encoded;
    }

    /**
     * The key a token belongs to, WITHIN one morph type.
     *
     * Scoped rather than global, and that is the whole point of the owner column: a token
     * that belongs to another model type now returns null — a clean 404 — instead of handing
     * back an id that this model would happily resolve against its own table.
     *
     * The untyped lane is searched as well, and only as a fallback, because a token issued
     * before tokens had owners is still a valid URL and must keep resolving. It cannot
     * disclose anything the old behavior did not: those rows are exactly the ones every
     * model already shared.
     */
    public function keyFor(string $token, string $type = self::UNTYPED): int|string|null
    {
        $memo = $type."\0".$token;

        if (array_key_exists($memo, $this->decoded)) {
            return $this->decoded[$memo];
        }

        $this->trim($this->decoded);

        // No ordering between the two lanes, and none is needed: `token` carries its own
        // unique index, so at most ONE row can match and there is nothing to rank. A CASE
        // ordering stood here, which reads as though a token could sit in both lanes at once —
        // it cannot, and the statement paid for a sort over a single row to say so.
        $key = DB::table('polyslug_tokens')
            ->where('token', $token)
            ->whereIn('key_type', array_unique([$type, self::UNTYPED]))
            ->value('key_value');

        return $this->decoded[$memo] = is_string($key) ? $key : null;
    }

    /**
     * Keep the newer half of a memo that has reached its ceiling.
     *
     * Called before an entry is added rather than after, and never in the middle of a batch,
     * so a call always finds what it has just written. Halving rather than dropping one entry
     * at a time keeps the cost of a trim spread over the many inserts that follow it.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $memo
     */
    private function trim(array &$memo): void
    {
        if (count($memo) >= $this->memoLimit) {
            $memo = array_slice($memo, -intdiv($this->memoLimit + 1, 2), null, true);
        }
    }

    /**
     * The keys of many tokens WITHIN one morph type, in one query per thousand tokens.
     *
     * The bulk counterpart of keyFor(), with its semantics: the untyped lane is searched as a
     * fallback, a token of another type or one nothing holds is absent from the result, and a
     * miss is remembered like a hit, so asking again costs nothing.
     *
     * @param  list<string>  $tokens
     * @return array<array-key, int|string>
     */
    public function keysFor(array $tokens, string $type = self::UNTYPED): array
    {
        $memo = fn (string $token): string => $type."\0".$token;

        $this->trim($this->decoded);

        $wanted = array_values(array_unique($tokens));
        $missing = array_values(array_filter($wanted, fn (string $token): bool => ! array_key_exists($memo($token), $this->decoded)));

        foreach (array_chunk($missing, self::SLICE) as $slice) {
            foreach ($slice as $token) {
                $this->decoded[$memo($token)] = null;
            }

            // At most one row per token: `token` carries its own unique index, across both lanes.
            $rows = DB::table('polyslug_tokens')
                ->whereIn('token', $slice)
                ->whereIn('key_type', array_unique([$type, self::UNTYPED]))
                ->get(['token', 'key_value']);

            foreach ($rows as $row) {
                $token = $this->columnString($row->token ?? null);

                if ($token !== null) {
                    $this->decoded[$memo($token)] = $this->columnString($row->key_value ?? null);
                }
            }
        }

        $keys = [];

        foreach ($wanted as $token) {
            $key = $this->decoded[$memo($token)] ?? null;

            if ($key !== null) {
                $keys[$token] = $key;
            }
        }

        return $keys;
    }

    /**
     * A column value as a string, or null when it is not one.
     *
     * Every property on a row object is `mixed` as far as static analysis is concerned, and
     * that is not pedantry — a driver can hand back a resource or an object for some column
     * types. Casting blind would turn that into a nonsense key rather than a miss.
     */
    private function columnString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Split rows read from both lanes into this owner's token and the untyped one.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array{0: string|null, 1: string|null}
     */
    private function splitLanes(Collection $rows, string $type): array
    {
        $own = null;
        $orphan = null;

        // The token column holds a string in this schema. columnString() narrows the row's
        // untyped value to that, and a row is filed into a lane only with one.
        foreach ($rows as $row) {
            $token = $this->columnString($row->token ?? null);

            if ($token !== null) {
                if ($this->columnString($row->key_type ?? null) === $type) {
                    $own = $token;
                } else {
                    $orphan = $token;
                }
            }
        }

        // When the caller IS the untyped lane there is no second lane to read, so a row can
        // only ever be $own. The null $orphan below is a fact of that shape, not a lookup
        // that came back empty.
        return [$own, $orphan];
    }

    /**
     * Move one untyped row under an owner, and say whether this caller is the one that got it.
     *
     * A conditional UPDATE rather than a read-then-write, so two records racing for the same
     * orphan cannot both take it: the loser sees zero rows affected and mints instead.
     *
     * PRECONDITION: $type is never the untyped lane. Both callers reach this only after
     * finding a row whose owner DIFFERS from theirs, and when the caller is itself the untyped
     * lane the read asks for one lane, so every row is its own. That is why the method does
     * not check for it.
     */
    private function claimOrphan(string $key, string $type): bool
    {
        return DB::table('polyslug_tokens')
            ->where('key_type', self::UNTYPED)
            ->where('key_value', $key)
            ->update(['key_type' => $type, 'updated_at' => Carbon::now()]) > 0;
    }

    /**
     * A lower bound on how many tokens have been handed out, read from the highest row id.
     *
     * The highest ID rather than a COUNT, for two reasons that point the same way: it is an
     * index lookup instead of a scan, and deleting a row below the highest does not move it. A
     * count drops with every deleted row, and each drop is one more taken candidate the claim
     * has to walk past; enough of them and a claim runs out of attempts.
     *
     * It is only ever a HINT. A counted scheme starts from it and walks forward until the
     * unique index accepts, so a bound that is behind costs attempts. What the index cannot
     * refuse is a token whose row is gone: deleting the highest row lowers the bound to the
     * row before it, and leaves the deleted token free to be drawn again, in a table filled
     * in order by the very next record. This package never deletes a token row, and an
     * application that prunes the table hands deleted tokens to new records.
     */
    private function issued(): int
    {
        $max = DB::table('polyslug_tokens')->max('id');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * Where a counted scheme starts looking on this attempt.
     *
     * A space the whole table shares starts past every row in it. A space of its own starts
     * from the rows of the type asking, so its shortest tokens are handed out first however
     * many rows the rest of the table holds. Starting past every row there would skip them:
     * twenty rows of another model and a three-letter alphabet at two characters lose all
     * nine two-character tokens before the model has used one.
     *
     * Only the first half of the attempts stays in the space of its own. A token of another
     * type can sit in it, and the unique index rejects that candidate however often it is
     * drawn, so the second half starts past every row in the table, the way a shared space
     * always does: the token is longer, and it is issued.
     */
    private function lowerBound(string $type, int $attempt): Closure
    {
        if ($this->ownSpace && $attempt < intdiv(self::CLAIM_ATTEMPTS, 2)) {
            return fn (): int => $this->issuedWithin($type);
        }

        return $this->issued(...);
    }

    /**
     * How many rows one type holds: the lower bound of a space of its own.
     *
     * A count rather than a highest id, because ids are shared with every other type and this
     * bound is about this type alone. A deleted row lowers it by one, and the next candidate is
     * then a token that still exists: the unique index rejects it and the claim walks on, out
     * of the space of its own once the first half of its attempts is spent. The tokens that can
     * come back are those of the newest rows, once they are the ones deleted, which is the same
     * limit the highest id has. This package deletes no token rows.
     */
    private function issuedWithin(string $type): int
    {
        return DB::table('polyslug_tokens')->where('key_type', $type)->count();
    }
}
