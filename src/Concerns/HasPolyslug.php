<?php

declare(strict_types=1);

namespace Polyslug\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\Sluggable;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Encoders\SqidsEncoder;
use Polyslug\Events\SlugChanged;
use Polyslug\Exceptions\CouldNotWriteSlug;
use Polyslug\Models\PolyslugShortLink;
use Polyslug\Models\PolyslugSlug;
use Polyslug\Polyslug;
use Polyslug\PolyslugConfig;
use Polyslug\PolyslugConfigResolver;
use Polyslug\Support\DeletionState;
use Polyslug\Support\SlugRequest;

/**
 * Gives an Eloquent model polymorphic, encoder-backed slugs. Declare the source with
 * the #[Polyslug] attribute and `implements Sluggable`; slugs are generated on save
 * and old slugs are superseded (kept as history) when the source changes.
 *
 * @mixin Model
 */
trait HasPolyslug
{
    protected static function bootHasPolyslug(): void
    {
        static::saved(static function (Model $model): void {
            if ($model instanceof Sluggable) {
                $model->polyslugSync();
            }
        });

        static::deleted(static function (Model $model): void {
            if ($model instanceof Sluggable) {
                $model->polyslugOnDeleted();
            }
        });
    }

    /**
     * @return MorphMany<PolyslugSlug, $this>
     */
    public function slugs(): MorphMany
    {
        return $this->morphMany(PolyslugSlug::class, 'sluggable');
    }

    public function currentSlug(?string $locale = null): ?string
    {
        return $this->currentSlugRow($locale)?->slug;
    }

    public function polyslugRouteKey(?string $locale = null): string
    {
        $locale ??= $this->polyslugLocale();
        $path = $this->polyslugPath($locale);

        // Slug-only mode: the URL is the slug/path alone, no "_{encodedId}".
        if ($this->polyslugConfig()->idLess) {
            return $path;
        }

        return Polyslug::compose($path, $this->polyslugEncodedKey());
    }

    public function polyslugRouteKeyForLocale(string $locale): string
    {
        return $this->polyslugRouteKey($locale);
    }

    public function polyslugParent(): ?Sluggable
    {
        return null;
    }

    public function polyslugPath(?string $locale = null, int $maxDepth = 20): string
    {
        $locale ??= $this->polyslugLocale();
        $own = $this->polyslugSlugForRouteKey($locale) ?? '';
        $parent = $this->polyslugParent();

        if ($parent === null || $maxDepth <= 0) {
            return $own;
        }

        // Prepend the ancestors' path (computed from their CURRENT slugs), so renaming or
        // reparenting an ancestor changes this URL — the canonical middleware then 301s the
        // stale one. maxDepth bounds a parent cycle.
        $prefix = $parent->polyslugPath($locale, $maxDepth - 1);

        return $prefix === '' ? $own : $prefix.'/'.$own;
    }

    public function shortLink(?string $locale = null): string
    {
        $locale ??= $this->polyslugLocale();

        return PolyslugShortLink::query()->firstOrCreate(
            [
                'sluggable_type' => $this->getMorphClass(),
                'sluggable_id' => $this->polyslugKeyString(),
                'locale' => $locale,
            ],
            ['token' => Str::lower(Str::random(10))],
        )->token;
    }

    private function polyslugSlugForRouteKey(string $locale): ?string
    {
        $slug = $this->currentSlug($locale);

        if ($slug !== null) {
            return $slug;
        }

        // The requested locale has no slug: fall back to the default locale's slug,
        // or emit a slug-less (id-only) key — per config polyslug.locale.missing.
        if (config('polyslug.locale.missing', 'fallback') === 'fallback') {
            return $this->currentSlug($this->polyslugDefaultLocale());
        }

        return null;
    }

    public function polyslugSync(?string $locale = null): void
    {
        $config = $this->polyslugConfig();
        $locale ??= $this->polyslugLocale();

        if ($this->currentSlugRow($locale) !== null && ($config->immutable || ! $this->wasChanged($config->source))) {
            return;
        }

        $this->writeSlug($locale, $this->polyslugSource($config), $config);
    }

    public function setSlug(string $locale, ?string $source = null): void
    {
        $config = $this->polyslugConfig();

        $this->writeSlug($locale, $source ?? $this->polyslugSource($config), $config);
    }

    /**
     * @return list<string>
     */
    public function slugLocales(): array
    {
        return array_values(
            $this->slugs()
                ->where('is_current', true)
                ->orderBy('locale')
                ->get()
                ->map(fn (PolyslugSlug $slug): string => $slug->locale)
                ->all()
        );
    }

    /**
     * @return list<string>
     */
    public function slugHistory(?string $locale = null): array
    {
        return array_values(
            $this->slugs()
                ->where('locale', $locale ?? $this->polyslugLocale())
                ->where('is_current', false)
                ->orderByDesc('id')
                ->get()
                ->map(fn (PolyslugSlug $slug): string => $slug->slug)
                ->all()
        );
    }

    /**
     * Build an absolute URL for each locale that has a current slug.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function polyslugUrls(callable $urlUsing): array
    {
        $urls = [];

        foreach ($this->slugLocales() as $locale) {
            if ($this->polyslugIsRoutable($locale)) {
                $urls[$locale] = $urlUsing($locale, $this->polyslugRouteKey($locale));
            }
        }

        return $urls;
    }

    /**
     * The reciprocal hreflang set: one self-referential entry per locale plus x-default.
     * The same resolver feeds this and the canonical URL, so they cannot disagree.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function hreflangLinks(callable $urlUsing, ?string $xDefault = null): array
    {
        $urls = $this->polyslugUrls($urlUsing);

        if ($urls === []) {
            return [];
        }

        $xDefault ??= $this->polyslugDefaultLocale();
        $urls['x-default'] = $urls[$xDefault] ?? reset($urls);

        return $urls;
    }

    /**
     * Render <link rel="alternate" hreflang="..."> tags for this model.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function hreflangTags(callable $urlUsing, ?string $xDefault = null): HtmlString
    {
        $tags = [];

        foreach ($this->hreflangLinks($urlUsing, $xDefault) as $hreflang => $url) {
            $tags[] = sprintf('<link rel="alternate" hreflang="%s" href="%s">', e($hreflang), e($url));
        }

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * Render <xhtml:link rel="alternate" hreflang="..."> alternates for a sitemap URL entry.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function sitemapAlternateTags(callable $urlUsing, ?string $xDefault = null): HtmlString
    {
        $tags = [];

        foreach ($this->hreflangLinks($urlUsing, $xDefault) as $hreflang => $url) {
            $tags[] = sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', e($hreflang), e($url));
        }

        return new HtmlString(implode("\n", $tags));
    }

    private function writeSlug(string $locale, string $source, PolyslugConfig $config): void
    {
        $scope = $this->polyslugScope($config);
        $attempts = $this->polyslugMaxWriteAttempts();
        $failure = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $current = $this->currentSlugRow($locale);

            $desired = app(SlugGenerator::class)->generate(
                new SlugRequest(
                    source: $source,
                    sluggableType: $this->getMorphClass(),
                    locale: $locale,
                    scope: $scope,
                    exceptId: $this->polyslugKeyString(),
                ),
                $config,
            );

            if ($current !== null && $current->slug === $desired) {
                return;
            }

            try {
                // Demote-old + insert-new atomically so a failure never leaves the model
                // with zero (or two) current slugs.
                DB::transaction(function () use ($current, $locale, $scope, $desired): void {
                    $current?->update(['is_current' => false]);

                    $this->slugs()->create([
                        'locale' => $locale,
                        'scope' => $scope,
                        'slug' => $desired,
                        'is_current' => true,
                    ]);
                });
            } catch (QueryException $exception) {
                // A concurrent writer claimed this slug (or the one-current-row) between
                // our generate and insert, or the write deadlocked. Regenerate against the
                // now-committed state and retry, up to the configured attempts.
                $failure = $exception;

                continue;
            }

            event(new SlugChanged($this, $locale, $desired, $current?->slug));

            return;
        }

        throw new CouldNotWriteSlug($this->getMorphClass(), $source, $failure);
    }

    private function polyslugMaxWriteAttempts(): int
    {
        $attempts = config('polyslug.write.max_attempts', 5);

        return is_int($attempts) && $attempts >= 1 ? $attempts : 5;
    }

    public function getRouteKey(): string
    {
        return $this->polyslugRouteKey();
    }

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?static
    {
        $routeValue = is_scalar($value) ? (string) $value : '';

        [$slug, $encodedId] = Polyslug::split($routeValue);

        if ($encodedId === null || $encodedId === '') {
            // No id part: slug-only models resolve by their slug instead.
            return $this->polyslugConfig()->idLess ? $this->resolveBySlug($slug) : null;
        }

        $id = $this->polyslugDecode($encodedId);

        if ($id === null) {
            return null;
        }

        return $this->polyslugResolveByKey($id);
    }

    /**
     * Resolve this model type by primary key THROUGH the resolution gate
     * (polyslugResolveQuery), so tenant/visibility scoping applies uniformly to every
     * resolution path — bound routes, the polymorphic resolver, slug-only, and /go.
     */
    public function polyslugResolveByKey(mixed $key): ?static
    {
        return $this->polyslugResolveQuery($this->newQuery())->whereKey($key)->first();
    }

    /**
     * Resolve a slug-only URL by its slug: current slugs first (canonical), then
     * superseded ones (the canonical middleware then 301s to the current URL). The
     * resolve-query gate still applies, so a slug shared across scopes/tenants resolves
     * to the one this request may see.
     */
    private function resolveBySlug(string $value): ?static
    {
        // Nested slug-only URLs carry the ancestor path; the model's own slug is the leaf.
        $slug = Str::afterLast($value, '/');

        $ids = PolyslugSlug::query()
            ->where('sluggable_type', $this->getMorphClass())
            ->where('locale', $this->polyslugLocale())
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)])
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->pluck('sluggable_id')
            ->all();

        foreach ($ids as $id) {
            $model = $this->polyslugResolveByKey($id);

            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Decode a token with the current encoder, falling back to any configured
     * legacy decoders (in order) so URLs made by a previous encoder still resolve.
     */
    private function polyslugDecode(string $encodedId): int|string|null
    {
        $id = $this->polyslugEncoder()->decode($encodedId);

        if ($id !== null) {
            return $id;
        }

        $legacy = config('polyslug.legacy_decoders', []);

        foreach (is_array($legacy) ? $legacy : [] as $decoder) {
            if (! is_string($decoder)) {
                continue;
            }
            if (! class_exists($decoder)) {
                continue;
            }
            $instance = app($decoder);

            if ($instance instanceof IdentityEncoder) {
                $id = $instance->decode($encodedId);

                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function polyslugResolveQuery(Builder $query): Builder
    {
        return $query;
    }

    public function polyslugIsRoutable(?string $locale = null): bool
    {
        return true;
    }

    public function polyslugSupersededBy(): ?Sluggable
    {
        return null;
    }

    public function polyslugIsGone(): bool
    {
        return false;
    }

    public function polyslugOnDeleted(): void
    {
        if ($this->polyslugIsForceDeleting()) {
            // Hard or force delete: remove the slug rows so no orphaned URLs linger in
            // the resolver or a sitemap.
            $this->slugs()->forceDelete();

            return;
        }

        // Soft delete: free the slug for reuse only when the model opts in.
        if ($this->polyslugConfig()->onDelete === 'release') {
            $this->slugs()->delete();
        }
    }

    private function polyslugIsForceDeleting(): bool
    {
        return DeletionState::isForceDeleting($this);
    }

    private function currentSlugRow(?string $locale = null): ?PolyslugSlug
    {
        return $this->slugs()
            ->where('locale', $locale ?? $this->polyslugLocale())
            ->where('is_current', true)
            ->orderByDesc('id')
            ->first();
    }

    private function polyslugConfig(): PolyslugConfig
    {
        return PolyslugConfigResolver::resolve($this);
    }

    private function polyslugEncoder(): IdentityEncoder
    {
        $config = $this->polyslugConfig();
        $instance = $config->encoder === null ? app(IdentityEncoder::class) : app($config->encoder);

        if (! $instance instanceof IdentityEncoder) {
            throw new InvalidArgumentException(sprintf(
                'The #[Polyslug] encoder on %s must implement %s.',
                static::class,
                IdentityEncoder::class,
            ));
        }

        // Per-model Sqids options give this model its own token space.
        if ($config->encoderOptions !== [] && $instance instanceof SqidsEncoder) {
            $alphabet = $config->encoderOptions['alphabet'] ?? null;
            $minLength = $config->encoderOptions['min_length'] ?? null;

            return new SqidsEncoder(
                is_string($alphabet) ? $alphabet : null,
                is_int($minLength) ? $minLength : 0,
            );
        }

        return $instance;
    }

    private function polyslugEncodedKey(): string
    {
        return $this->polyslugEncoder()->encode($this->polyslugKeyString());
    }

    private function polyslugKeyString(): string
    {
        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    private function polyslugLocale(): string
    {
        return app()->getLocale();
    }

    private function polyslugDefaultLocale(): string
    {
        $configured = config('polyslug.locale.fallback_locale');

        return is_string($configured) && $configured !== '' ? $configured : app()->getFallbackLocale();
    }

    private function polyslugSource(PolyslugConfig $config): string
    {
        $parts = [];

        foreach ($config->source as $column) {
            $value = $this->getAttribute($column);

            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }

    private function polyslugScope(PolyslugConfig $config): string
    {
        $parts = [];

        foreach ($config->scope as $column) {
            $value = $this->getAttribute($column);
            $parts[] = $column.':'.(is_scalar($value) ? (string) $value : '');
        }

        return implode('|', $parts);
    }
}
