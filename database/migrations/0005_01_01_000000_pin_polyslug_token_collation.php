<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A token column must compare byte-exactly, or the entropy it promises is not the entropy
 * it has.
 *
 * `$table->string('token')` states no collation, so the column inherits the connection's.
 * On MySQL that default is case-INSENSITIVE, and the consequence is not cosmetic:
 * `TokenAlphabet` explicitly invites a mixed-case alphabet ("An application that wants the
 * entropy back passes its own alphabet") and its validation admits `A-Z`. An application
 * that takes the invitation gets a 62-character alphabet that the database counts as 36 —
 * roughly a factor of 2^25 at the default length of 16, on `/go/{token}`, the one path
 * RandomTokenScheme exists for. RandomTokenScheme::DRAWS_PER_LENGTH is calibrated against
 * 36^n as well, so its collision escalation fires later than the real space warrants.
 *
 * Measured 2026-09-05 on real servers, one probe per engine, the schema line verbatim
 * (`VARCHAR(255) NOT NULL UNIQUE`):
 *
 *   PostgreSQL 18 (en_US.UTF-8)     `token = 'AbC123'` finds 'abc123': no   -> both coexist
 *   SQLite                          the same: no                           -> both coexist
 *   MySQL 8.4, utf8mb4_unicode_ci   the same: YES, 1 row                    -> ERROR 1062
 *
 * So MySQL is the one engine out of step, and this migration is scoped to it. The control
 * that makes those zeros trustworthy: the same lookup for the exact string returns 1.
 *
 * ⚠️ THE DIRECTION IS SAFE, AND ONLY THIS DIRECTION IS. Case-insensitive to binary SPLITS
 * equivalence classes: every pair that was distinct under the old collation stays distinct
 * under the new one, so a unique index that held before still holds. The reverse would
 * merge them and could fail on live data. Nothing here rewrites a row.
 *
 * The behavior that changes is a lookup: on a default MySQL install `/go/ABC123` stops
 * resolving to the record holding `abc123`. That is the point rather than a side effect —
 * it never resolved on PostgreSQL or SQLite, so the package answered the same request two
 * ways depending on the engine underneath it.
 */
return new class extends Migration
{
    /** @var list<array{string, string}> table => column */
    private const array TOKEN_COLUMNS = [
        ['polyslug_tokens', 'token'],
        ['polyslug_short_links', 'token'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TOKEN_COLUMNS as [$table, $column]) {
            $current = $this->columnDefinition($table, $column);

            // Already byte-exact -> nothing to rebuild. An ALTER on a large indexed column
            // is not free, and an installation created under a binary connection collation
            // is already correct.
            if ($current === null || str_ends_with($current['collation'], '_bin')) {
                continue;
            }

            $this->modify($table, $column, $current, $current['charset'].'_bin');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TOKEN_COLUMNS as [$table, $column]) {
            $current = $this->columnDefinition($table, $column);
            $inherited = $this->tableCollation($table);

            if ($current === null || $inherited === null) {
                continue;
            }

            // Back to what the column INHERITED, which is the table's own default — that is
            // the state up() found, not a guess at one. Restoring a hard-coded
            // `utf8mb4_unicode_ci` would hand back a collation the installation may never
            // have had.
            $this->modify($table, $column, $current, $inherited);
        }
    }

    /**
     * The column's REAL shape, read rather than assumed. `string('token')` is varchar(255)
     * today, but a consumer may have narrowed or widened it, and an ALTER that restates the
     * type from memory would silently resize the column while claiming to touch only its
     * collation.
     *
     * @return array{type: string, charset: string, collation: string, nullable: string}|null
     */
    private function columnDefinition(string $table, string $column): ?array
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS type, CHARACTER_SET_NAME AS charset, '
            .'COLLATION_NAME AS collation, IS_NULLABLE AS nullable '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        // Every field is checked, not just the one being changed: the ALTER restates the
        // whole column, so a null anywhere in here would be written into the statement as an
        // empty string and silently redefine something.
        $fields = [];

        foreach (['type', 'charset', 'collation', 'nullable'] as $field) {
            $value = is_object($row) ? ($row->{$field} ?? null) : null;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $fields[$field] = $value;
        }

        return $fields;
    }

    private function tableCollation(string $table): ?string
    {
        $row = DB::selectOne(
            'SELECT TABLE_COLLATION AS collation FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $value = is_object($row) ? ($row->collation ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array{type: string, charset: string, collation: string, nullable: string}  $current
     */
    private function modify(string $table, string $column, array $current, string $collation): void
    {
        // Identifiers cannot be bound, and none of these is user input: both names are class
        // constants, and the type and charset came from information_schema on this very
        // connection.
        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET %s COLLATE %s %s',
            $table,
            $column,
            $current['type'],
            $current['charset'],
            $collation,
            $current['nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
        ));
    }
};
