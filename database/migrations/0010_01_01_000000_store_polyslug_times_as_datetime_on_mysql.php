<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On MySQL, the time columns of the package's tables are DATETIME rather than TIMESTAMP.
 *
 * `timestamps()`, `softDeletes()` and `timestamp()` declare TIMESTAMP there, and a TIMESTAMP has two
 * properties a slug row or a short link that is meant to hold forever cannot have. It ends at
 * 2038-01-19 03:14:07 UTC, and strict mode refuses every later value: measured on MySQL 8.4.10, an
 * insert of 2040-01-01 into `polyslug_slugs.created_at` failed with error 1292. And it is converted
 * through the session's time zone on every write and every read, so a value written under one
 * session zone reads back shifted under another. DATETIME stores the value it is given and has
 * neither property. PostgreSQL's `timestamp without time zone` and SQLite's text already behave
 * that way, so nothing changes there.
 *
 * The conversion keeps every value as the application reads it: MySQL turns each stored TIMESTAMP
 * into the session's local time, the same conversion every read applied until now, and this runs on
 * the application's own connection. Measured on the same server under the session zone +02:00: a row
 * written as 07:00 read 07:00 before, after up() and after down().
 *
 * Changing the type rewrites each of the three tables once.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> each table, with its time columns */
    private const array TIME_COLUMNS = [
        'polyslug_slugs' => ['created_at', 'updated_at', 'deleted_at', 'retired_at'],
        'polyslug_tokens' => ['created_at', 'updated_at'],
        'polyslug_short_links' => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->convert('timestamp', static fn (Blueprint $table, string $column) => $table->dateTime($column)->nullable()->change());
    }

    public function down(): void
    {
        $this->convert('datetime', static fn (Blueprint $table, string $column) => $table->timestamp($column)->nullable()->change());
    }

    /**
     * Every time column that is of $from today, changed by $change, one ALTER per table so a table is
     * rewritten once. Only on MySQL, and only columns that are there and of that type, so a column an
     * application already changed is left as it is.
     *
     * @param  Closure(Blueprint, string): mixed  $change
     */
    private function convert(string $from, Closure $change): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TIME_COLUMNS as $table => $columns) {
            $columns = array_values(array_filter($columns, fn (string $column): bool => $this->dataType($table, $column) === $from));

            if ($columns === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($columns, $change): void {
                foreach ($columns as $column) {
                    $change($blueprint, $column);
                }
            });
        }
    }

    private function dataType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [Schema::getConnection()->getTablePrefix().$table, $column],
        );

        $type = is_object($row) ? ($row->type ?? null) : null;

        return is_string($type) ? strtolower($type) : null;
    }
};
