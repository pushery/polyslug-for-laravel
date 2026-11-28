<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Polyslug\Support\TableLookup;

/**
 * The resolution index, rebuilt with `lower(slug)` ahead of `scope`.
 *
 * Slug resolution always asks for `sluggable_type`, `locale` and `lower(slug)`, and asks for
 * `scope` only when the model names its resolution scope (polyslugResolutionScope()), which
 * none does by default. The index 0004 created put `scope` third, and an index can seek only
 * as far as its leading columns are given, so a resolution without a scope narrowed to the
 * type and locale and compared the slug across every row they hold.
 *
 * With `lower(slug)` third, a resolution seeks on three columns with or without a scope, and
 * on all four with one. Measured with 100,000 rows of one type and one locale, resolving a
 * slug without a scope, before and after:
 *
 *   MySQL 8.4         index not used, 100,000 rows read   100 ms      ->  0.013 ms, 1 row
 *   PostgreSQL 18     skip scan, 1,003 buffers (1,000 scope values)  4.41 ms  ->  0.024 ms
 *   SQLite            skip scan over every scope value  ->  one seek
 *
 * PostgreSQL and SQLite bridge the missing column with a skip scan, so with a single scope
 * value they lose little; each further scope value costs them another search. MySQL does not
 * use the index at all.
 *
 * The new index is built before the old one is dropped, so the table is never without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('polyslug_slugs_resolution_by_slug', slugBeforeScope: true);
        $this->drop('polyslug_slugs_resolution');
    }

    public function down(): void
    {
        $this->create('polyslug_slugs_resolution', slugBeforeScope: false);
        $this->drop('polyslug_slugs_resolution_by_slug');
    }

    private function create(string $name, bool $slugBeforeScope): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Prefix lengths, for the reason 0004 gives: four utf8mb4 varchar(255) columns are
            // past the 3072-byte key limit. The functional part takes none.
            $columns = $slugBeforeScope
                ? 'sluggable_type(64), locale(16), (LOWER(slug)), scope(64)'
                : 'sluggable_type(64), locale(16), scope(64), (LOWER(slug))';

            DB::statement('CREATE INDEX '.$name.' ON '.$this->table('polyslug_slugs').' ('.$columns.')');

            return;
        }

        $columns = $slugBeforeScope
            ? 'sluggable_type, locale, lower(slug), scope'
            : 'sluggable_type, locale, scope, lower(slug)';

        DB::statement('CREATE INDEX '.$this->wrapped($this->index($name)).' ON '.$this->table('polyslug_slugs').' ('.$columns.')');
    }

    private function drop(string $name): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX '.$name.' ON '.$this->table('polyslug_slugs'));

            return;
        }

        DB::statement('DROP INDEX '.$this->wrapped($this->standing($name)));
    }

    /**
     * A table as raw SQL has to name it: with the connection's prefix, quoted by its grammar.
     */
    private function table(string $name): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrapTable($name);
    }

    /**
     * The name of an index of this package on the connection. PostgreSQL and SQLite allow an index
     * name once per schema, so there the name carries the table prefix where the connection
     * prefixes its indexes, as a name Laravel derives does: a second install under another prefix in
     * the same schema builds its own index. MySQL allows an index name once per table, and the name
     * stays as it is.
     */
    private function index(string $name): string
    {
        $connection = Schema::getConnection();

        return in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true) && (bool) $connection->getConfig('prefix_indexes')
            ? $connection->getTablePrefix().$name
            : $name;
    }

    /** An index name as raw SQL writes it, quoted by the connection's grammar. */
    private function wrapped(string $name): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrap($name);
    }

    /**
     * The name an index stands under on the slug table: the one index() gives, or the one an install
     * built before its name carried the prefix. Asked of the table itself, so the index of another
     * install in the same schema is never the answer.
     */
    private function standing(string $name): string
    {
        $named = $this->index($name);

        return $named !== $name && ! Schema::hasIndex(TableLookup::qualified('polyslug_slugs'), $named) && Schema::hasIndex(TableLookup::qualified('polyslug_slugs'), $name)
            ? $name
            : $named;
    }
};
