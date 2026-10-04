<?php

declare(strict_types=1);

namespace Polyslug\Generators;

use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Str;
use Override;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Exceptions\CouldNotGenerateSlug;
use Polyslug\Models\PolyslugSlug;
use Polyslug\PolyslugConfig;
use Polyslug\Support\ReservedWords;
use Polyslug\Support\SlugRequest;

final class DefaultSlugGenerator implements SlugGenerator
{
    /**
     * How many suffixed names one query asks about, at most. The batches double from two, so the
     * n-th record with the same title costs about log2(n) queries rather than n, and the cap keeps
     * a batch far below the bound-parameter limit of every engine.
     */
    private const int SUFFIX_BATCH_LIMIT = 256;

    #[Override]
    public function generate(SlugRequest $request, PolyslugConfig $config): string
    {
        if ($config->slugless) {
            // A slugless URL is the encoded token alone, so there is no name to build, no
            // source to read and nothing to collide with. Returning before slugify() rather
            // than letting it fall out the empty-source path is what keeps that true: that
            // path is governed by emptyFallback, which a consumer may set to 'throw', and a
            // model that declares it has no slug must not be able to fail for not having one.
            return '';
        }

        $base = $this->slugify($request->source, $config);

        // The id-only fallback on a slug-only model. An empty slug leaves an id-based URL with its
        // token alone, `_{encodedId}`; a slug-only URL has no token, so an empty slug would be no
        // address at all, and the next record's `-2` an address made of a suffix. The record's
        // encoded identity takes the slug's place instead.
        if ($base === '' && $config->idLess && $request->identity instanceof Closure) {
            $base = ($request->identity)();
        }

        if (! $config->unique) {
            // `unique: false` opts out of BOTH the disambiguating suffix and the uniqueness
            // guarantee: records may share a slug, because a non-idLess URL (slug_id) resolves
            // by the encoded id, not the slug. The row is written with enforce_unique = false,
            // so the current_unique index skips it. idLess + unique:false is rejected at config
            // time (MisconfiguredPolyslug), so this branch is always non-idLess.
            return $base;
        }

        // The model's own list when HasPolyslug resolved one, the inherited list otherwise. A
        // model that filters or clears its reserved words does so through
        // polyslugReservedWords(), and by the time the request arrives here that answer is
        // already baked in.
        $reserved = array_map(Str::lower(...), $request->reserved ?? ReservedWords::inherited($config));

        $slug = $this->firstFree([$base], $reserved, $request, $config);

        // A taken base often has taken suffixes too, because a common title gathers namesakes.
        // Asked about one at a time, the n-th record with the same title cost n queries on its
        // save. The suffixes are asked about in batches that double from two instead, each batch
        // one lookup of the exact lower-cased names the unique index compares, so every name is
        // still an index probe, the n-th record costs about log2(n) queries, and the smallest free
        // suffix is the one returned.
        $suffix = 2;
        $size = 2;

        while ($slug === null) {
            $candidates = [];

            for ($next = $suffix; $next < $suffix + $size; $next++) {
                $candidates[] = $this->withSuffix($base, $config->separator, $config->separator.$next);
            }

            $slug = $this->firstFree($candidates, $reserved, $request, $config);
            $suffix += $size;
            $size = min($size * 2, self::SUFFIX_BATCH_LIMIT);
        }

        return $slug;
    }

    /**
     * The longest slug the `slug` column holds.
     *
     * The column is `string('slug')`, so it is as long as the schema builder's default string
     * length was when the migration ran, 255 unless the application lowered it with
     * `Schema::defaultStringLength()` in a provider, where it is still lowered at runtime. A
     * longer slug is refused by PostgreSQL after the record itself was saved, and cut short by
     * MySQL's `INSERT IGNORE`, so that the next record with the same title can never be written.
     */
    private function columnLength(): int
    {
        return max(1, Builder::$defaultStringLength);
    }

    /**
     * The base with a uniqueness suffix, the base shortened where the two would not fit the
     * column together, without leaving a separator at the cut.
     */
    private function withSuffix(string $base, string $separator, string $suffix): string
    {
        $room = $this->columnLength() - mb_strlen($suffix);

        return (mb_strlen($base) > $room ? rtrim(mb_substr($base, 0, max(0, $room)), $separator) : $base).$suffix;
    }

    private function slugify(string $source, PolyslugConfig $config): string
    {
        $slug = match (true) {
            $config->unicode === 'native' => $this->slugifyNative($source, $config->separator),
            $config->preserveCase => $this->slugifyPreservingCase($source, $config),
            default => Str::slug($source, $config->separator, $config->transliterate->language()),
        };

        // `maxLength` may shorten the slug further, never past the column: without it, a long
        // title used to produce a slug the column could not hold.
        $limit = min($config->maxLength ?? PHP_INT_MAX, $this->columnLength());

        if (mb_strlen($slug) > $limit) {
            $slug = trim(mb_substr($slug, 0, $limit), $config->separator);
        }

        if ($slug === '') {
            if ($config->emptyFallback === 'throw') {
                throw new CouldNotGenerateSlug($source);
            }

            // id-only fallback: an empty slug means the URL is just "_{encodedId}", so a
            // title with no sluggable characters (CJK/emoji-only) still saves cleanly.
            return '';
        }

        return $slug;
    }

    /**
     * The ASCII slugify of `Str::slug()`, minus the one thing it does not let you turn off.
     *
     * `Str::slug()` folds as part of stripping characters, and takes no flag for it — so a
     * model that wants to keep the writing its owner chose (`Octo-Org`, a mirrored handle)
     * needs the same transformation without that call. Transliterate first, then reduce every
     * run of non-(letter/number) to a single separator, which is what is left of `Str::slug()`
     * once the fold is removed.
     *
     * A SECOND IMPLEMENTATION IS A SECOND THING TO GET WRONG, so it is held to the first:
     * a test asserts that lower-casing this result lands exactly on what `Str::slug()` emits
     * for the same source. Nothing here may drift into producing a different SHAPE — the only
     * difference this method is allowed to make is the case.
     *
     * Safe only because `unicode: 'ascii'` transliterates: every character that survives is
     * ASCII, so the case-insensitive unique index folds it the same way on every engine. The
     * config refuses `preserveCase` together with `unicode: 'native'` for exactly that reason.
     */
    private function slugifyPreservingCase(string $source, PolyslugConfig $config): string
    {
        $ascii = Str::ascii($source, $config->transliterate->language());

        // `Str::slug()` runs a dictionary before it strips, and its default maps `@` to `at`.
        // Leaving it out is not a cosmetic difference: `Me@Example` would become `Me-Example`
        // here and `me-at-example` on the ordinary path, so the same source would produce two
        // different slugs depending on a flag that is only supposed to change the case. The
        // drift test found this exact case; it is the reason that test exists.
        $ascii = str_replace('@', $config->separator.'at'.$config->separator, $ascii);

        $slug = preg_replace('/[^\p{L}\p{N}]+/u', $config->separator, $ascii) ?? '';

        return trim($slug, $config->separator);
    }

    /**
     * Unicode-preserving slugify for non-Latin scripts. Lower-cases at generation
     * (mb-aware) so the stored slug is already folded — the case-insensitive unique
     * index then behaves identically on PostgreSQL (Unicode lower()) and SQLite
     * (ASCII-only lower()), which would otherwise disagree on non-ASCII letters.
     * Assumes NFC-normalized input.
     */
    private function slugifyNative(string $source, string $separator): string
    {
        $lower = mb_strtolower($source);

        // Collapse every run of non-(letter/number) into a single separator.
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $lower) ?? '';

        return trim($slug, $separator);
    }

    /**
     * The first of $candidates, in their order, that is neither reserved nor held by a competing
     * row, or null when every one of them is taken.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $reserved  lower-cased
     */
    private function firstFree(array $candidates, array $reserved, SlugRequest $request, PolyslugConfig $config): ?string
    {
        $open = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => ! in_array(Str::lower($candidate), $reserved, true),
        ));

        // Names the reserved list already refuses need no query to be known as taken.
        if ($open === []) {
            return null;
        }

        $taken = $this->takenInStore($open, $request, $config);

        foreach ($open as $candidate) {
            if (! in_array(Str::lower($candidate), $taken, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The names among $slugs, lower-cased, that a competing UNIQUENESS-ENFORCING row already holds
     * in the store for this (type, locale, scope), the model's own rows excluded. It mirrors the
     * current_unique index, which covers only enforce_unique rows, so a unique:false record
     * (enforce_unique = false, free to share a slug) never counts as a collision here. The
     * reserved list is firstFree()'s business, not this one's.
     *
     * @param  non-empty-list<string>  $slugs
     * @return list<string>
     */
    private function takenInStore(array $slugs, SlugRequest $request, PolyslugConfig $config): array
    {
        // `reclaimActive` takes the name from whoever holds it, so NOTHING in the store is a
        // collision and no counter suffix is ever appended. The handover itself is the write
        // path's job (it retires the holder's row inside the same transaction as the insert); all
        // that is decided here is that the generator must not steer around the name first.
        // Returning early rather than dropping the is_current filter below, because with the
        // filter dropped a RETIRED row of another model would still read as a collision and the
        // suffix would come back for exactly the case reclaim exists to serve.
        if ($config->reclaimActive) {
            return [];
        }

        $lowered = array_map(Str::lower(...), $slugs);

        // On the connection the request names, which is where the model reads its slugs. A
        // request without one keeps the slug model's own, rather than overwriting a connection
        // a replaced slug model declares.
        $query = ($request->connection === null ? PolyslugSlug::model()::query() : PolyslugSlug::model()::on($request->connection))
            ->where('sluggable_type', $request->sluggableType)
            ->where('locale', $request->locale)
            ->where('scope', $request->scope)
            ->where('enforce_unique', true)
            ->whereRaw('lower(slug) in ('.implode(', ', array_fill(0, count($lowered), '?')).')', $lowered);

        // For id-based models only current slugs collide (a superseded slug is free to
        // reuse — the id still disambiguates). Slug-only models must also reserve retired
        // slugs, or an old URL could resolve to a different model.
        //
        // `reclaim: true` opts out of exactly that reservation, and only a slug-only model
        // may set it. It is for names the application does not own — a mirrored account, an
        // external registry — where the source has already handed the name to somebody else
        // and reserving it makes the mirror disagree with what it mirrors. The retired row
        // stays put and keeps serving history; it no longer blocks the name.
        if (! $config->idLess || $config->reclaim) {
            $query->where('is_current', true);
        }

        if ($request->exceptId !== null) {
            $query->where('sluggable_id', '!=', $request->exceptId);
        }

        $taken = [];

        foreach ($query->pluck('slug') as $slug) {
            if (is_string($slug)) {
                $taken[] = Str::lower($slug);
            }
        }

        return $taken;
    }
}
