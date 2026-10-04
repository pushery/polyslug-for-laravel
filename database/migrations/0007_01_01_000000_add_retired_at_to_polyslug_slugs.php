<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a former slug was retired: the moment it stopped leading to its record.
 *
 * A former slug redirects to the record's current address, which is what keeps old links alive
 * after a rename. A slug retired with HasPolyslug::retireSlug() answers with
 * `polyslug.retired.status` instead, for the rename that must not keep pointing at the record,
 * such as one forced by a trademark complaint. Null on every row this migration finds, so
 * nothing changes until a slug is retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polyslug_slugs', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('polyslug_slugs', function (Blueprint $table): void {
            $table->dropColumn('retired_at');
        });
    }
};
