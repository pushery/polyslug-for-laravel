<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a stored token an owner.
     *
     * Until now polyslug_tokens mapped `key_value -> token` and key_value was the primary-key
     * VALUE alone. The morph type was not part of it, so two models whose ids collide — which
     * is every pair of tables, at id 1 — shared a row and therefore the same token. Resolution
     * stayed correct, because the route names the model type, but the URL of one model was
     * enough to construct the URL of every other Polyslug model holding that id. That is
     * weaker than what RandomTokenEncoder advertises, and polyslug_short_links — solving the
     * same problem one table over — keys on the full target instead.
     *
     * THIS CHANGES WHICH TOKEN A RECORD GETS, and only a backfill keeps that from breaking
     * published URLs. The backfill is the substance of this migration, not a courtesy: without
     * it, the first render after deploying would find no row for (type, id) and mint a new
     * token for EVERY record.
     *
     * key_type is NOT NULL with an empty default rather than nullable. Nulls do not collide in
     * a unique index on any of the three engines, so a nullable column would let two rows hold
     * the same key_value — reintroducing, in the untyped lane, exactly the ambiguity this
     * migration removes.
     */
    public function up(): void
    {
        Schema::table('polyslug_tokens', function (Blueprint $table): void {
            $table->string('key_type')->default('')->after('id');
        });

        $this->backfillOwners();

        // Only now is the wider key safe to enforce: the rows have owners, so
        // (key_type, key_value) is already distinct everywhere key_value was.
        Schema::table('polyslug_tokens', function (Blueprint $table): void {
            $table->dropUnique('polyslug_tokens_key_value_unique');
            $table->unique(['key_type', 'key_value']);
        });
    }

    public function down(): void
    {
        // Reversible only as far as the SCHEMA goes, and the difference is worth stating: two
        // rows may legitimately share a key_value now, so restoring a unique index over it
        // alone can fail against data this migration made valid. Rolling back therefore also
        // drops the rows that would collide — the ones a second model claimed after the
        // upgrade — keeping the row that owns the lowest id, which is the one that held the
        // token before the split.
        $this->dropRowsThatWouldCollideOnKeyValueAlone();

        Schema::table('polyslug_tokens', function (Blueprint $table): void {
            $table->dropUnique('polyslug_tokens_key_type_key_value_unique');
            $table->unique('key_value');
            $table->dropColumn('key_type');
        });
    }

    /**
     * Give every existing token the type of the model that holds it, read from polyslug_slugs.
     *
     * DETERMINISTIC where it can be, and it can be almost everywhere: polyslug_slugs already
     * records (sluggable_type, sluggable_id), so the owner of a key is a lookup rather than a
     * guess. Where several types hold the same id — the very collision this migration exists
     * to separate — the OLDEST slug row wins. One of them has to keep the token, nothing in
     * the data says which deserves it more, and "whoever published first keeps their URL" is
     * the answer that breaks the fewest links.
     *
     * A token with no slug row at all keeps the empty type. That is an orphan — its model was
     * deleted, or its URL was never rendered — and inventing an owner for it would be a guess
     * that could take the token away from a model that still wants it. TokenStore adopts such
     * a row on first use instead, which is the one place that knows who is asking.
     *
     * Chunked, because this runs on a production table of unknown size and a single UPDATE
     * with a correlated subquery would load it all at once. It is also the portable shape: the
     * ORDER BY + LIMIT tiebreak inside a correlated UPDATE subquery is not something all three
     * engines agree on.
     *
     * Two queries per chunk rather than two per ROW: one to read the owners of the whole
     * chunk's keys, one UPDATE per distinct type. On a table with a few types and many rows —
     * which is every application this runs on — that is the difference between a migration
     * that finishes and one an operator kills.
     */
    private function backfillOwners(): void
    {
        DB::table('polyslug_tokens')
            ->where('key_type', '')
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows): void {
                /** @var list<string> $keys */
                $keys = $rows->pluck('key_value')
                    ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                    ->values()
                    ->all();

                // Ordered by id so the OLDEST slug row is seen first, and $claimed is what
                // makes "first seen wins" the tiebreak — in PHP, where all three engines
                // behave the same.
                //
                // Grouped by TYPE while reading, so the write below is one UPDATE per type
                // carrying every key that type owns in this chunk. Collecting key => type
                // first and inverting afterwards would look tidier and be wrong: PHP
                // normalizes a numeric array key to an int, so the keys would come back out
                // as ints and reach a varchar column as integer bindings.
                $claimed = [];
                $byType = [];

                DB::table('polyslug_slugs')
                    ->select(['sluggable_id', 'sluggable_type'])
                    ->whereIn('sluggable_id', $keys)
                    ->orderBy('id')
                    ->each(function (object $slug) use (&$claimed, &$byType): void {
                        $id = $slug->sluggable_id ?? null;
                        $type = $slug->sluggable_type ?? null;

                        if (! is_scalar($id) || ! is_string($type) || $type === '') {
                            return;
                        }

                        $key = (string) $id;

                        if (isset($claimed[$key])) {
                            return;
                        }

                        $claimed[$key] = true;
                        $byType[$type][] = $key;
                    });

                foreach ($byType as $type => $ownedKeys) {
                    DB::table('polyslug_tokens')
                        ->where('key_type', '')
                        ->whereIn('key_value', $ownedKeys)
                        ->update(['key_type' => $type]);
                }
            });
    }

    /** @see self::down() — the rows a rollback cannot keep. */
    private function dropRowsThatWouldCollideOnKeyValueAlone(): void
    {
        $duplicates = DB::table('polyslug_tokens')
            ->select('key_value')
            ->groupBy('key_value')
            ->havingRaw('count(*) > 1')
            ->pluck('key_value');

        foreach ($duplicates as $keyValue) {
            $keep = DB::table('polyslug_tokens')->where('key_value', $keyValue)->orderBy('id')->value('id');

            DB::table('polyslug_tokens')
                ->where('key_value', $keyValue)
                ->where('id', '!=', $keep)
                ->delete();
        }
    }
};
