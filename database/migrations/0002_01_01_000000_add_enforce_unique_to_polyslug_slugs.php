<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A per-row opt-out from slug uniqueness. Default true, so every existing slug keeps
        // its uniqueness guarantee. A #[Polyslug(unique: false)] model (non-idLess only)
        // writes false, excluding its rows from the current_unique index — two records may
        // then share a slug, because a non-idLess URL (slug_id) resolves by the encoded id,
        // not the slug. The one_current index is left untouched: each model still keeps
        // exactly one current row. idLess models always stay enforce_unique = true — they
        // resolve BY slug, so their slugs must remain unique.
        Schema::table('polyslug_slugs', function (Blueprint $table): void {
            $table->boolean('enforce_unique')->default(true);
        });

        // Rebuild ONLY current_unique so it covers just the rows that enforce uniqueness; the
        // mechanism mirrors 0001 per engine. one_current is deliberately not rebuilt.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX polyslug_slugs_current_unique ON '.$this->table('polyslug_slugs'));
            DB::statement('ALTER TABLE '.$this->table('polyslug_slugs').' DROP COLUMN polyslug_current_key');
            DB::statement(
                'ALTER TABLE '.$this->table('polyslug_slugs').' ADD COLUMN polyslug_current_key CHAR(64) '
                .'GENERATED ALWAYS AS (CASE WHEN is_current = 1 AND deleted_at IS NULL AND enforce_unique = 1 '
                .'THEN SHA2(CONCAT_WS(CHAR(30), sluggable_type, locale, scope, LOWER(slug)), 256) END) VIRTUAL'
            );
            DB::statement('CREATE UNIQUE INDEX polyslug_slugs_current_unique ON '.$this->table('polyslug_slugs').' (polyslug_current_key)');
        } else {
            DB::statement('DROP INDEX polyslug_slugs_current_unique');
            DB::statement(
                'CREATE UNIQUE INDEX polyslug_slugs_current_unique '
                .'ON '.$this->table('polyslug_slugs').' (sluggable_type, locale, scope, lower(slug)) '
                .'WHERE is_current AND deleted_at IS NULL AND enforce_unique'
            );
        }
    }

    public function down(): void
    {
        // Checked before any statement runs. Since up(), the records of a `unique: false` model
        // may share a current slug, and the unconditional index restored below refuses that. On
        // MySQL every statement commits as it runs, so a rollback that failed halfway through
        // would leave the table without a unique index on its current slugs.
        $sharedSlugs = DB::table('polyslug_slugs')
            ->selectRaw('1')
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->groupBy('sluggable_type', 'locale', 'scope')
            ->groupByRaw('lower(slug)')
            ->havingRaw('count(*) > 1');

        $shared = DB::query()->fromSub($sharedSlugs, 'shared_slugs')->count();

        if ($shared > 0) {
            throw new RuntimeException(sprintf(
                'Migration add_enforce_unique_to_polyslug_slugs cannot be rolled back: %d current slug(s) '
                .'are held by more than one record of a `unique: false` model, and the unique index the '
                .'rollback restores refuses that. Rename those records so their slugs differ, then roll '
                .'back again. Nothing was changed.',
                $shared,
            ));
        }

        // Restore the unconditional current_unique first (drops the enforce_unique reference),
        // then drop the column.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX polyslug_slugs_current_unique ON '.$this->table('polyslug_slugs'));
            DB::statement('ALTER TABLE '.$this->table('polyslug_slugs').' DROP COLUMN polyslug_current_key');
            DB::statement(
                'ALTER TABLE '.$this->table('polyslug_slugs').' ADD COLUMN polyslug_current_key CHAR(64) '
                .'GENERATED ALWAYS AS (CASE WHEN is_current = 1 AND deleted_at IS NULL '
                .'THEN SHA2(CONCAT_WS(CHAR(30), sluggable_type, locale, scope, LOWER(slug)), 256) END) VIRTUAL'
            );
            DB::statement('CREATE UNIQUE INDEX polyslug_slugs_current_unique ON '.$this->table('polyslug_slugs').' (polyslug_current_key)');
        } else {
            DB::statement('DROP INDEX polyslug_slugs_current_unique');
            DB::statement(
                'CREATE UNIQUE INDEX polyslug_slugs_current_unique '
                .'ON '.$this->table('polyslug_slugs').' (sluggable_type, locale, scope, lower(slug)) '
                .'WHERE is_current AND deleted_at IS NULL'
            );
        }

        Schema::table('polyslug_slugs', function (Blueprint $table): void {
            $table->dropColumn('enforce_unique');
        });
    }

    /**
     * A table as raw SQL has to name it: with the connection's prefix, quoted by its grammar.
     * The schema and query builders apply the prefix themselves; a statement written by hand
     * gets it only through here.
     */
    private function table(string $name): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrapTable($name);
    }
};
