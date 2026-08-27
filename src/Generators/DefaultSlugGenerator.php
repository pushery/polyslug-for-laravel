<?php

declare(strict_types=1);

namespace Polyslug\Generators;

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

        if (! $config->unique) {
            // `unique: false` opts out of BOTH the disambiguating suffix and the uniqueness
            // guarantee: records may share a slug, because a non-idLess URL (slug_id) resolves
            // by the encoded id, not the slug. The row is written with enforce_unique = false,
            // so the current_unique index skips it. idLess + unique:false is rejected at config
            // time (MisconfiguredPolyslug), so this branch is always non-idLess.
            return $base;
        }

        $slug = $base;
        $suffix = 1;

        while ($this->isTaken($slug, $request, $config)) {
            $suffix++;
            $slug = $base.$config->separator.$suffix;
        }

        return $slug;
    }

    private function slugify(string $source, PolyslugConfig $config): string
    {
        $slug = $config->unicode === 'native'
            ? $this->slugifyNative($source, $config->separator)
            : Str::slug($source, $config->separator, $config->transliterate->language());

        if ($config->maxLength !== null && mb_strlen($slug) > $config->maxLength) {
            $slug = trim(mb_substr($slug, 0, $config->maxLength), $config->separator);
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

    private function isTaken(string $slug, SlugRequest $request, PolyslugConfig $config): bool
    {
        $lowerSlug = Str::lower($slug);

        // The model's own list when the trait resolved one, the inherited list otherwise. A
        // model that filters or clears its reserved words does so through
        // polyslugReservedWords(), and by the time the request arrives here that answer is
        // already baked in.
        foreach ($request->reserved ?? ReservedWords::inherited($config) as $reserved) {
            if (Str::lower($reserved) === $lowerSlug) {
                return true;
            }
        }

        return $this->existsInStore($slug, $request, $config);
    }

    /**
     * Whether a competing UNIQUENESS-ENFORCING slug row already exists in the store for this
     * (type, locale, scope), the model's own rows excluded. It mirrors the current_unique
     * index, which covers only enforce_unique rows — so a unique:false record (enforce_unique
     * = false, free to share a slug) never counts as a collision here. Unlike isTaken() it
     * ignores the reserved list.
     */
    private function existsInStore(string $slug, SlugRequest $request, PolyslugConfig $config): bool
    {
        $query = PolyslugSlug::query()
            ->where('sluggable_type', $request->sluggableType)
            ->where('locale', $request->locale)
            ->where('scope', $request->scope)
            ->where('enforce_unique', true)
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)]);

        // For id-based models only current slugs collide (a superseded slug is free to
        // reuse — the id still disambiguates). Slug-only models must also reserve retired
        // slugs, or an old URL could resolve to a different model.
        //
        // `reclaim: true` opts out of exactly that reservation, and only a slug-only model
        // may set it. It is for names the application does not own — a mirrored account, an
        // external registry — where the source has already handed the name to somebody else
        // and reserving it makes the mirror disagree with what it mirrors. The retired row
        // stays put and keeps serving history; it simply no longer blocks the name.
        // `reclaimActive` goes one step further: the name is taken from whoever holds it, so
        // NOTHING in the store is a collision and no counter suffix is ever appended. The
        // handover itself is the write path's job (it retires the holder's row inside the same
        // transaction as the insert); all that is decided here is that the generator must not
        // steer around the name first. Returning early rather than dropping the is_current
        // filter, because with the filter dropped a RETIRED row of another model would still
        // read as a collision and the suffix would come back for exactly the case reclaim exists
        // to serve.
        if ($config->reclaimActive) {
            return false;
        }

        if (! $config->idLess || $config->reclaim) {
            $query->where('is_current', true);
        }

        if ($request->exceptId !== null) {
            $query->where('sluggable_id', '!=', $request->exceptId);
        }

        return $query->exists();
    }
}
