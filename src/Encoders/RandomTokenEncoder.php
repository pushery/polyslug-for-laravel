<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Exceptions\CouldNotIssueToken;

/**
 * Leak-safe identity: each key maps to an unguessable random token kept in the
 * polyslug_tokens table. The URL token reveals nothing about the key — no row count,
 * order, or value — so it suits enumeration-sensitive, integer-keyed tables. The
 * token is stable per key (one row), so each record keeps a single canonical URL.
 *
 * NOTE — encode() WRITES on first use. Rendering a link issues an INSERT the first
 * time a given key is encoded, and never again afterwards. That rules this encoder
 * out on a read-only replica connection, and it makes the first render of a batch of
 * new records the moment of peak write contention. Both are inherent to storing the
 * mapping; the retry loop below is what makes them safe rather than fatal.
 */
final class RandomTokenEncoder implements IdentityEncoder
{
    /**
     * How many times encode() re-attempts a claim before giving up.
     *
     * Every attempt loses only to a CONCURRENT writer, and each loss teaches the loop
     * something: either the key is now taken (adopt that token and stop) or this
     * particular random draw was taken (draw another). Five matches the ceiling the
     * slug write path uses; needing a second pass would already be remarkable.
     */
    private const int CLAIM_ATTEMPTS = 5;

    /** @var array<string, string> */
    private array $encoded = [];

    /** @var array<string, int|string|null> */
    private array $decoded = [];

    #[Override]
    public function encode(int|string $id): string
    {
        $key = (string) $id;

        if (isset($this->encoded[$key])) {
            return $this->encoded[$key];
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
            $lookup = DB::table('polyslug_tokens')->where('key_value', $key);

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

            $existing = $lookup->value('token');

            if (is_string($existing)) {
                return $this->encoded[$key] = $existing;
            }

            $token = Str::lower(Str::random(16));

            $inserted = DB::table('polyslug_tokens')->insertOrIgnore([
                'key_value' => $key,
                'token' => $token,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($inserted > 0) {
                return $this->encoded[$key] = $token;
            }

            // Zero rows means SOME unique index rejected this row, and the table carries
            // two: key_value (a concurrent writer claimed this key — the next iteration's
            // SELECT finds their token and returns it) and token (this random draw
            // collided — the next iteration draws a fresh one). Both recover by looping,
            // which is why the loop re-reads rather than assuming which one it hit;
            // telling them apart here would need the engine-specific error the ignore
            // just swallowed.
        }

        throw new CouldNotIssueToken($key, self::CLAIM_ATTEMPTS);
    }

    #[Override]
    public function decode(string $token): int|string|null
    {
        if (array_key_exists($token, $this->decoded)) {
            return $this->decoded[$token];
        }

        $key = DB::table('polyslug_tokens')->where('token', $token)->value('key_value');

        return $this->decoded[$token] = is_string($key) ? $key : null;
    }
}
