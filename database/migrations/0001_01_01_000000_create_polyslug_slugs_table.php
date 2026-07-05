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
        Schema::create('polyslug_slugs', function (Blueprint $table): void {
            $table->id();
            // String morph key so any host key type resolves (int, UUID, ULID, token).
            $table->string('sluggable_type');
            $table->string('sluggable_id');
            $table->string('locale', 16);
            // Uniqueness scope (e.g. a tenant key); '' means global per type + locale.
            $table->string('scope')->default('');
            $table->string('slug');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sluggable_type', 'sluggable_id', 'locale'], 'polyslug_slugs_sluggable_index');
        });

        // Exactly one current, non-deleted slug per (type, locale, scope, case-insensitive
        // slug). A functional partial unique index expresses this without a generated
        // column — PostgreSQL 18 makes generated columns VIRTUAL and therefore
        // non-indexable, and slug transliteration is not an immutable built-in anyway.
        // The identical statement runs on PostgreSQL and SQLite (>= 3.9).
        DB::statement(
            'CREATE UNIQUE INDEX polyslug_slugs_current_unique '
            .'ON polyslug_slugs (sluggable_type, locale, scope, lower(slug)) '
            .'WHERE is_current AND deleted_at IS NULL'
        );

        // Exactly ONE current, non-deleted slug per (type, id, locale, scope). Without
        // this a concurrent rename could leave two is_current rows, making the "current"
        // slug — and therefore the canonical URL — nondeterministic (a flapping 301).
        DB::statement(
            'CREATE UNIQUE INDEX polyslug_slugs_one_current '
            .'ON polyslug_slugs (sluggable_type, sluggable_id, locale, scope) '
            .'WHERE is_current AND deleted_at IS NULL'
        );

        // Backing store for the optional RandomTokenEncoder: an unguessable, leak-free
        // token per key. Empty (and inert) unless a model opts into that encoder.
        Schema::create('polyslug_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('key_value');
            $table->string('token');
            $table->timestamps();

            $table->unique('key_value');
            $table->unique('token');
        });

        // Optional /go short links: one stable token per (model, locale) that 301s to the
        // model's CURRENT canonical URL, so a printed/QR link survives slug renames.
        Schema::create('polyslug_short_links', function (Blueprint $table): void {
            $table->id();
            $table->string('token');
            $table->string('sluggable_type');
            $table->string('sluggable_id');
            $table->string('locale', 16);
            $table->timestamps();

            $table->unique('token');
            $table->unique(['sluggable_type', 'sluggable_id', 'locale'], 'polyslug_short_links_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polyslug_short_links');
        Schema::dropIfExists('polyslug_tokens');
        Schema::dropIfExists('polyslug_slugs');
    }
};
