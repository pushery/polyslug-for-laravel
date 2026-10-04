<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On PostgreSQL, the slug column folds ASCII letters the way PHP does, whatever the database's
 * locale.
 *
 * Uniqueness and every slug lookup compare `lower(slug)`, and lower() follows the collation of the
 * column, which a column without one takes from the database. The package folds the name it looks
 * for in PHP, where `I` becomes `i`. A database created with a Turkish or Azerbaijani locale folds
 * `I` to the dotless `ı`. Measured 2026-10-04 on PostgreSQL 18 with an ICU `tr-TR` database and a
 * model with `preserveCase`: the second record titled "IOS App" was refused with CouldNotWriteSlug
 * instead of getting `IOS-App-2`, because the name looked free in PHP and taken in the unique index,
 * and the first record's own URL `IOS-App` answered 404. Under `en_US.UTF-8` and under `C` both
 * worked.
 *
 * So up() asks the column whether lower() turns the ASCII capitals into the ASCII small letters, and
 * only where it does not, pins the column to the `C` collation, which folds ASCII and nothing else.
 * That is all a stored slug needs: an `ascii` slug is ASCII, and a `native` one is lower-cased in PHP
 * when it is generated, and the configuration refuses `preserveCase` together with it. A database
 * whose locale folds ASCII as PHP does is left as it is, so most installations change nothing.
 *
 * Changing the collation of the column rebuilds the indexes over it once, while the table is locked.
 * Moving to `C` can only split names the old collation folded together, never join two, so the
 * rebuilt unique indexes cannot refuse a row they hold.
 */
return new class extends Migration
{
    private const string CAPITALS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $column = $this->column();

        if ($column === null || $this->foldsAsciiAsPhpDoes($column['collation'])) {
            return;
        }

        $this->collate($column['type'], 'C');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $column = $this->column();

        // Back to the database's own collation, which is where up() found a column it pinned.
        if ($column === null || $column['collation'] !== 'C') {
            return;
        }

        $this->collate($column['type'], 'default');
    }

    /**
     * The type of the slug column as PostgreSQL states it and the name of its collation, read rather
     * than assumed, because the ALTER restates the type. Null when the column is not there.
     *
     * @return array{type: string, collation: string}|null
     */
    private function column(): ?array
    {
        $row = DB::selectOne(
            'SELECT format_type(a.atttypid, a.atttypmod) AS type, co.collname AS collation '
            .'FROM pg_attribute a JOIN pg_collation co ON co.oid = a.attcollation '
            .'WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND NOT a.attisdropped',
            [Schema::getConnection()->getTablePrefix().'polyslug_slugs', 'slug'],
        );

        $type = is_object($row) ? ($row->type ?? null) : null;
        $collation = is_object($row) ? ($row->collation ?? null) : null;

        return is_string($type) && $type !== '' && is_string($collation) && $collation !== ''
            ? ['type' => $type, 'collation' => $collation]
            : null;
    }

    /**
     * Whether lower() under the column's collation turns the ASCII capitals into the ASCII small
     * letters. The column's own collation is named unless it is the database's default, which
     * applies to a literal without a COLLATE clause anyway.
     */
    private function foldsAsciiAsPhpDoes(string $collation): bool
    {
        $literal = $collation === 'default'
            ? "'".self::CAPITALS."'"
            : "'".self::CAPITALS."' COLLATE ".Schema::getConnection()->getQueryGrammar()->wrap($collation);

        $row = DB::selectOne('SELECT lower('.$literal.') AS folded');

        return is_object($row) && ($row->folded ?? null) === strtolower(self::CAPITALS);
    }

    private function collate(string $type, string $collation): void
    {
        // Identifiers cannot be bound, and none of these is input: the table carries the
        // connection's prefix, the type came from the catalog of this very connection, and the
        // collation is one of two names written above.
        $grammar = Schema::getConnection()->getQueryGrammar();

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE %s COLLATE %s',
            $grammar->wrapTable('polyslug_slugs'),
            $grammar->wrap('slug'),
            $type,
            $grammar->wrap($collation),
        ));
    }
};
