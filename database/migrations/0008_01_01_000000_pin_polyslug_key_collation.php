<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On MySQL, the columns that say which record a row belongs to and under which name compare byte
 * for byte, the way the unique keys over them do.
 *
 * `$table->string()` states no collation, so these columns inherit the connection's:
 * `utf8mb4_unicode_ci` in Laravel's default configuration, `utf8mb4_0900_ai_ci` where the server's
 * default applies. Both ignore accents and case. The two unique keys of `polyslug_slugs` are
 * generated columns there and hash the bytes of `sluggable_type`, `sluggable_id`, `locale`, `scope`
 * and `LOWER(slug)`, while every lookup on those columns compares under the collation, so the
 * lookups and the keys disagree about what one name is. In `polyslug_tokens` and
 * `polyslug_short_links` the unique indexes themselves follow the collation, so two records whose
 * string keys differ only in case are one record to them.
 *
 * Measured 2026-10-03 on MySQL 8.0.36 under both collations, through the package's own API:
 *
 *   'Résumé', then 'Resume', native slugs   the second record gets 'resume-2', and an id-less
 *                                           /resume resolves to the first
 *   'Café' held, 'Cafe' created on a        the takeover retires 'café': its record is left
 *   reclaimActive model                     without a current slug, and /café resolves to the
 *                                           other record
 *   records keyed 'Abc' and 'abc'           one token row for both, the URL of 'abc' resolves
 *                                           to 'Abc', and both get the same short link
 *
 * Scopes that differ only in case or accent collapse the same way. Under a binary collation each
 * of these behaves as on PostgreSQL and SQLite: 'resume' is free, every name and every key leads
 * to its own record, and the takeover leaves 'café' alone.
 *
 * Moving a column from an insensitive collation to a binary one splits equivalence classes and
 * never merges them, so no lookup starts matching a row it did not match before and no unique
 * index can start refusing a row it holds. The generated keys hash bytes the ALTER does not
 * rewrite, and LOWER() folds nothing but ASCII in a stored slug: a native slug is lower-cased when
 * it is generated, and the configuration refuses preserveCase together with it. Measured on the
 * same servers, every row kept both of its generated keys.
 *
 * The behavior that changes is a lookup, and on purpose: on a default MySQL installation /resume
 * stops resolving to the record that holds 'résumé', and a record keyed 'abc' that shared the token
 * of 'Abc' gets a token of its own the next time its URL is rendered. Neither ever happened on
 * PostgreSQL or SQLite.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> each table, with the string columns its keys and lookups compare */
    private const array KEY_COLUMNS = [
        'polyslug_slugs' => ['sluggable_type', 'sluggable_id', 'locale', 'scope', 'slug'],
        'polyslug_tokens' => ['key_type', 'key_value'],
        'polyslug_short_links' => ['sluggable_type', 'sluggable_id', 'locale'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::KEY_COLUMNS as $name => $columns) {
            $table = Schema::getConnection()->getTablePrefix().$name;
            $changes = [];

            foreach ($columns as $column) {
                $current = $this->columnDefinition($table, $column);

                // Already byte-exact, or not there to change. An installation created under a
                // binary connection collation is correct as it stands, and an ALTER on an indexed
                // column rebuilds the table.
                if ($current === null || str_ends_with($current['collation'], '_bin')) {
                    continue;
                }

                $changes[$column] = [$current, $current['charset'].'_bin'];
            }

            $this->modify($table, $changes);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::KEY_COLUMNS as $name => $columns) {
            $table = Schema::getConnection()->getTablePrefix().$name;
            $inherited = $this->tableCollation($table);
            $changes = [];

            foreach ($columns as $column) {
                $current = $this->columnDefinition($table, $column);

                // Back to what the column inherited, which is its table's own default: the state
                // up() found, not a guess at one.
                if ($current === null || $inherited === null || $current['collation'] === $inherited) {
                    continue;
                }

                $changes[$column] = [$current, $inherited];
            }

            $this->modify($table, $changes);
        }
    }

    /**
     * The column's real shape, read rather than assumed: the ALTER restates the whole column, so a
     * width, a default or a comment it did not carry over would be silently redefined.
     *
     * @return array{type: string, charset: string, collation: string, nullable: string, default: string|null, comment: string}|null
     */
    private function columnDefinition(string $table, string $column): ?array
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, '
            .'IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, COLUMN_COMMENT AS comment '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        // A missing table or column answers no row, and every required field is then null: the
        // definition is refused rather than written into the statement with holes in it.
        $fields = [];

        foreach (['type', 'charset', 'collation', 'nullable'] as $field) {
            $value = is_object($row) ? ($row->{$field} ?? null) : null;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $fields[$field] = $value;
        }

        $default = is_object($row) ? ($row->default_value ?? null) : null;
        $comment = is_object($row) ? ($row->comment ?? null) : null;

        return [...$fields, 'default' => is_string($default) ? $default : null, 'comment' => is_string($comment) ? $comment : ''];
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
     * One ALTER per table for every column that changes, so a table is rebuilt once rather than
     * once per column.
     *
     * @param  array<string, array{array{type: string, charset: string, collation: string, nullable: string, default: string|null, comment: string}, string}>  $changes
     */
    private function modify(string $table, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $pdo = DB::getPdo();
        $clauses = [];

        foreach ($changes as $column => [$current, $collation]) {
            // Identifiers cannot be bound, and none of these is user input: the column names are a
            // class constant, the table carries the connection's prefix, and the type, charset,
            // default and comment came from information_schema on this very connection, the last
            // two quoted by it.
            $clauses[] = sprintf(
                'MODIFY `%s` %s CHARACTER SET %s COLLATE %s %s%s%s',
                $column,
                $current['type'],
                $current['charset'],
                $collation,
                $current['nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
                $current['default'] === null ? '' : ' DEFAULT '.$pdo->quote($current['default']),
                $current['comment'] === '' ? '' : ' COMMENT '.$pdo->quote($current['comment']),
            );
        }

        DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
    }
};
