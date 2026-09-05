<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index for the path every incoming URL takes.
 *
 * Slug resolution asks for `sluggable_type = ? AND locale = ? AND scope = ? AND lower(slug) = ?`
 * (HasPolyslug::resolveBySlug). Until now `lower(slug)` appeared in exactly one index — the
 * PARTIAL unique index that carries the one-current-slug guarantee — and every resolution was a
 * full table scan.
 *
 * ⚠️ THE CAUSE IS STATISTICS, NOT REACHABILITY, and that distinction is why the fix is a second
 * index rather than a rewritten query. PostgreSQL gathers no expression statistics from a
 * PARTIAL expression index, so `lower(slug) = ?` falls back to the default 0.5% guess: at
 * 100,000 rows the planner expects 500 matches where there is 1, and on that estimate a scan
 * really is cheaper than the index. It is choosing correctly from wrong numbers.
 *
 * Measured 2026-09-05, outside a transaction, after ANALYZE:
 *
 *   PostgreSQL 18, 100k rows   Seq Scan, 99,999 discarded   18.504 ms  ->  0.094 ms   (197x)
 *   MySQL 8.4,      20k rows   type=ALL, rows=19283         18.407 ms  ->  0.120 ms   (153x)
 *   SQLite,         20k rows   SCAN                          3.445 ms
 *
 * Additive and non-authoritative: the partial unique index stays exactly as it is and keeps
 * carrying uniqueness. This one carries only the seek and the statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Prefix lengths are required: four utf8mb4 varchar(255) columns are far past the
            // 3072-byte key limit. They are wide enough to stay selective — a morph class name
            // and a scope key differ long before 64 characters — and the functional part takes
            // no prefix, so LOWER(slug) is indexed whole.
            DB::statement(
                'CREATE INDEX polyslug_slugs_resolution ON polyslug_slugs '
                .'(sluggable_type(64), locale(16), scope(64), (LOWER(slug)))'
            );

            return;
        }

        // PostgreSQL + SQLite: the same functional index, deliberately WITHOUT the partial
        // WHERE clause. Adding it back would reproduce the defect this migration exists for.
        DB::statement(
            'CREATE INDEX polyslug_slugs_resolution ON polyslug_slugs '
            .'(sluggable_type, locale, scope, lower(slug))'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX polyslug_slugs_resolution ON polyslug_slugs');

            return;
        }

        DB::statement('DROP INDEX polyslug_slugs_resolution');
    }
};
