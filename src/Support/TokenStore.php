<?php

declare(strict_types=1);

namespace Polyslug\Support;

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

    /** @var array<string, string> */
    private array $encoded = [];

    /** @var array<string, int|string|null> */
    private array $decoded = [];

    public function __construct(private readonly TokenScheme $scheme) {}

    public function tokenFor(int|string $id, string $type = self::UNTYPED): string
    {
        $key = (string) $id;
        $memo = $type."\0".$key;

        if (isset($this->encoded[$memo])) {
            return $this->encoded[$memo];
        }

        // Read-then-write is a RACE, and this runs on the URL-render path: two requests
        // rendering the same never-before-encoded model both miss the SELECT and both
        // INSERT, so the loser takes a unique-constraint violation — a 500 on a GET,
        // intermittent and unreproducible after the fact. insertOrIgnore turns that loss
        // into a return value instead of an exception (catching a duplicate-key error is
        // not portable across engines — the same reason the slug write path uses it), and
        // the loser then adopts the winner's token: both requests emit the same canonical
        // URL, which is the correct outcome anyway.
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

            $token = $this->scheme->draw($attempt, $this->issued(...));

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
     * One SELECT for every key, one INSERT for the ones that are missing.
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

        // Cast BACK to string, and this is not redundant. PHP normalizes a numeric string
        // array key to an int, so array_keys() on a map built from `(string) $id` hands back
        // ints for every numeric id — which is every default Eloquent key. The keys are
        // strings everywhere else in this class, so without this the memo closure below is
        // handed an int and dies on its own type hint.
        /** @var list<string> $wanted */
        $wanted = array_map(strval(...), array_keys($keys));
        $missing = array_values(array_filter($wanted, fn (string $key): bool => ! isset($this->encoded[$memo($key)])));

        if ($missing !== []) {
            // BOTH lanes, exactly as the single-key path reads them, and for the same reason:
            // a record whose token predates owners must be ADOPTED rather than issued a second
            // one. Missing that here was not a slow path, it was a wrong one — a single
            // polyslugPreload() over such records would mint fresh tokens for all of them and
            // retire every URL they were published under, silently and in bulk.
            $rows = DB::table('polyslug_tokens')
                ->whereIn('key_type', array_unique([$type, self::UNTYPED]))
                ->whereIn('key_value', $missing)
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

            $unclaimed = array_values(array_filter($missing, fn (string $key): bool => ! isset($this->encoded[$memo($key)])));

            if ($unclaimed !== []) {
                $now = Carbon::now();
                $offset = 0;

                // Asked at most once for the whole batch, and only if the scheme asks at all:
                // a random scheme never opens the closure, and paying for a MAX(id) it does
                // not read would put a query back into the one path that exists to remove
                // them. Memoized rather than re-read per row because nothing in this batch
                // is written yet, so the answer cannot change while the map runs.
                $issued = null;
                $counted = function () use (&$issued): int {
                    return $issued ??= $this->issued();
                };

                DB::table('polyslug_tokens')->insertOrIgnore(array_map(
                    // Each row gets its own candidate, and a COUNTED scheme needs them to
                    // differ: it answers from how many tokens exist, and nothing in this
                    // batch has been written yet, so every row would otherwise be handed the
                    // same next number and all but one would be dropped by the unique index.
                    // The offset walks the count forward as if the earlier rows had landed.
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

        // Written as a positive branch rather than a `null` guard with a `continue`: a column
        // that is not a string cannot happen against this schema, so the guard would be a
        // statement no run can execute — and the coverage gate is right to object to one.
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
     * ⚠️ PRECONDITION: $type is never the untyped lane. Both callers reach this only after
     * finding a row whose owner DIFFERS from theirs, and when the caller is itself the untyped
     * lane the read asks for one lane, so every row is its own. A guard for that case was here
     * and is gone: it was a branch no run can enter, which the coverage gate correctly refuses
     * and which reads to the next person like a case that happens.
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
     * index lookup instead of a scan, and a deleted row must not hand its token back to a
     * counted scheme — an id is never reissued, a count is.
     *
     * It is only ever a HINT. A counted scheme starts from it and walks forward until the
     * unique index accepts, so a bound that is behind costs attempts, never correctness.
     */
    private function issued(): int
    {
        $max = DB::table('polyslug_tokens')->max('id');

        return is_numeric($max) ? (int) $max : 0;
    }
}
