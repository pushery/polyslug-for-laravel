<?php

declare(strict_types=1);

namespace Polyslug\Generators;

use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Override;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Exceptions\CouldNotGenerateSlug;
use Polyslug\Models\PolyslugSlug;
use Polyslug\PolyslugConfig;
use Polyslug\Support\SlugRequest;

final class DefaultSlugGenerator implements SlugGenerator
{
    #[Override]
    public function generate(SlugRequest $request, PolyslugConfig $config): string
    {
        $base = $this->slugify($request->source, $config);

        if (! $config->unique) {
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

        foreach ($this->reservedWords($config) as $reserved) {
            if (Str::lower($reserved) === $lowerSlug) {
                return true;
            }
        }

        $query = PolyslugSlug::query()
            ->where('sluggable_type', $request->sluggableType)
            ->where('locale', $request->locale)
            ->where('scope', $request->scope)
            ->whereRaw('lower(slug) = ?', [$lowerSlug]);

        // For id-based models only current slugs collide (a superseded slug is free to
        // reuse — the id still disambiguates). Slug-only models must also reserve retired
        // slugs, or an old URL could resolve to a different model.
        if (! $config->idLess) {
            $query->where('is_current', true);
        }

        if ($request->exceptId !== null) {
            $query->where('sluggable_id', '!=', $request->exceptId);
        }

        return $query->exists();
    }

    /**
     * The per-model reserved words plus the app-wide polyslug.reserved.global list.
     *
     * @return list<string>
     */
    private function reservedWords(PolyslugConfig $config): array
    {
        $global = config('polyslug.reserved.global', []);
        $reserved = array_merge($config->reserved, is_array($global) ? array_values(array_filter($global, is_string(...))) : []);

        if (config('polyslug.reserved.from_routes') === true) {
            return array_merge($reserved, $this->registeredRoutePaths());
        }

        return $reserved;
    }

    /**
     * The static first segment of every registered route, so a generated slug can never
     * shadow a real route (e.g. /login, /admin) when polyslug.reserved.from_routes is on.
     *
     * @return list<string>
     */
    private function registeredRoutePaths(): array
    {
        $paths = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $first = explode('/', $route->uri())[0];

            if ($first !== '' && ! str_contains($first, '{')) {
                $paths[] = $first;
            }
        }

        return array_values(array_unique($paths));
    }
}
