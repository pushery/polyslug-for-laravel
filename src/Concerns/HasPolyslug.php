<?php

declare(strict_types=1);

namespace Polyslug\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Polyslug\Contracts\BulkIdentityEncoder;
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

    /**
     * Warm the identity tokens for a whole set of models in one round trip.
     *
     * The companion to eager-loading `slugs`. That removes the per-model SLUG query; this
     * removes the per-model TOKEN query, which is what the database-backed default encoder
     * costs on the first render of each row:
     *
     *     $pages = Page::query()->with('slugs')->paginate();
     *     Page::polyslugPreload($pages);
     *
     * Ordinary work afterwards — route keys, hreflang sets, sitemaps — issues no further
     * token queries, because the encoder is bound as a singleton and answers from the memo
     * this call filled.
     *
     * A NO-OP WHERE THERE IS NOTHING TO WIN, and silently so, on purpose. Sqids, UUID, ULID
     * and the raw key derive their token from the key alone; they do not implement
     * BulkIdentityEncoder, and calling this with them costs one array walk. So a consumer
     * may write it unconditionally without first knowing which encoder is configured — the
     * point of an optimization hint is that it does not become a configuration question.
     *
     * Models are grouped by their RESOLVED encoder rather than assumed to share one:
     * `#[Polyslug(encoder: ...)]` is per-model, so a mixed set would otherwise send keys to
     * the wrong store.
     *
     * @param  iterable<mixed>  $models  narrowed by the instanceof below rather than by the
     *                                   signature: typing this as iterable<Sluggable> makes
     *                                   the guard provably dead on a class that uses the
     *                                   trait WITHOUT implementing Sluggable — which is a
     *                                   supported shape here, and PHPStan says so
     */
    public static function polyslugPreload(iterable $models): void
    {
        /** @var array<int, array{encoder: BulkIdentityEncoder, keys: list<string>}> $batches */
        $batches = [];

        foreach ($models as $model) {
            // instanceof static, because the private helpers below are reachable only on an
            // instance of this very class. A foreign Sluggable is skipped rather than
            // fataling: preloading is a hint, and a hint must not be able to break a render.
            if (! $model instanceof static) {
                continue;
            }

            $encoder = $model->polyslugEncoder();

            if (! $encoder instanceof BulkIdentityEncoder) {
                continue;
            }

            $handle = spl_object_id($encoder);
            $batches[$handle] ??= ['encoder' => $encoder, 'keys' => []];
            $batches[$handle]['keys'][] = $model->polyslugKeyString();
        }

        foreach ($batches as $batch) {
            $batch['encoder']->encodeMany($batch['keys']);
        }
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
        if (Container::getInstance()->make(ConfigRepository::class)->get('polyslug.locale.missing', 'fallback') === 'fallback') {
            return $this->currentSlug($this->polyslugDefaultLocale());
        }

        return null;
    }

    public function polyslugSync(?string $locale = null): void
    {
        $config = $this->polyslugConfig();
        $locale ??= $this->polyslugLocale();

        // fresh: a write decides against what is current NOW, never against a collection
        // loaded before this request touched anything.
        $current = $this->currentSlugRow($locale, fresh: true);

        if ($current !== null && ($config->immutable || ! $this->wasChanged($config->source))) {
            return;
        }

        // Handing the row on is the whole point of naming it: writeSlug()'s first attempt
        // asked the identical question again, in the same call stack, with nothing in
        // between that could change the answer.
        $this->writeSlug($locale, $this->polyslugSource($config), $config, known: $current);
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
        // Reads the eager-loaded relation for the same reason currentSlugRow() does, and
        // it matters more here: this is what polyslugUrls() calls first, so every hreflang
        // set, every <head> and every sitemap entry used to open with its own query.
        if ($this->relationLoaded('slugs')) {
            return array_values(
                $this->currentSlugsInMemory(null)
                    ->map(fn (PolyslugSlug $slug): string => $slug->locale)
                    ->sort()
                    ->values()
                    ->all()
            );
        }

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

    /**
     * `$known` carries what the caller already established about the current row by reading it
     * fresh in this same call stack. Three states, deliberately distinct: the row itself, `null`
     * for "I looked and there is none", `false` for "I did not look". Collapsing the middle one
     * into `false` would hand the duplicate read straight back to the path that issues the most
     * of them — `polyslug:backfill` walks rows that have no slug and whose source has not
     * changed, so `null` is its normal answer, not its edge case.
     *
     * @param  PolyslugSlug|false|null  $known  the caller's fresh answer, or false if it has none
     */
    private function writeSlug(string $locale, string $source, PolyslugConfig $config, PolyslugSlug|false|null $known = false): void
    {
        $scope = $this->polyslugScope($config);
        $attempts = $this->polyslugMaxWriteAttempts();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // fresh, and this is the attempt that makes it non-negotiable: the loop re-asks
            // after a failed attempt precisely because another writer may have moved the row.
            // A cached collection would hand back the same superseded row on every pass.
            //
            // Only the FIRST pass may take the caller's answer, and only because that read
            // happened microseconds ago with no write between. Every later pass exists
            // BECAUSE the world moved, so reusing $known there would defeat the retry.
            $current = $attempt === 1 && $known !== false
                ? $known
                : $this->currentSlugRow($locale, fresh: true);

            $desired = Container::getInstance()->make(SlugGenerator::class)->generate(
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

            // Demote-old + insert-new in one transaction that always COMMITS. insertOrIgnore
            // skips (returns 0), without throwing, a slug a concurrent writer claimed between
            // our generate and insert; on that miss we restore the demoted row inside the same
            // transaction, so the model always keeps exactly one current slug. Because the write
            // never rolls back a nested savepoint, it stays correct inside an outer transaction
            // on every engine — MySQL's savepoint rollback is unreliable once DDL has implicitly
            // committed the outer transaction. Exhausting the attempts throws outside any
            // transaction, leaving the original current slug untouched.
            $inserted = DB::transaction(function () use ($current, $locale, $scope, $desired, $config): int {
                $current?->update(['is_current' => false]);

                $inserted = PolyslugSlug::query()->insertOrIgnore([
                    'sluggable_type' => $this->getMorphClass(),
                    'sluggable_id' => $this->polyslugKeyString(),
                    'locale' => $locale,
                    'scope' => $scope,
                    'slug' => $desired,
                    'is_current' => true,
                    // A non-idLess unique:false model opts its rows out of the current_unique
                    // index so records may share a slug (the id in the URL disambiguates).
                    // idLess is always unique (enforced by MisconfiguredPolyslug at config time).
                    'enforce_unique' => $config->unique,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                if ($inserted === 0) {
                    $current?->update(['is_current' => true]);
                }

                return $inserted;
            });

            if ($inserted > 0) {
                Container::getInstance()->make(Dispatcher::class)->dispatch(new SlugChanged($this, $locale, $desired, $current?->slug));

                return;
            }
        }

        throw new CouldNotWriteSlug($this->getMorphClass(), $source);
    }

    private function polyslugMaxWriteAttempts(): int
    {
        $attempts = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.write.max_attempts', 5);

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

        $legacy = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.legacy_decoders', []);

        foreach (is_array($legacy) ? $legacy : [] as $decoder) {
            if (! is_string($decoder)) {
                continue;
            }
            if (! class_exists($decoder)) {
                continue;
            }
            $instance = Container::getInstance()->make($decoder);

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

    /**
     * The current slug row for a locale.
     *
     * Reads an EAGER-LOADED `slugs` relation when one is present, so a rendered list pays
     * one query for every model instead of one per model per read. Without this, `slugs()`
     * hands back the relation BUILDER and every read issues a fresh SELECT — which made
     * `->with('slugs')` actively worse than not eager-loading at all: one extra query, and
     * nothing using it.
     *
     * `$fresh` is not an optimization switch, it is a correctness one, and it is why this
     * takes a parameter rather than always preferring the relation. A loaded collection
     * describes the world at the moment it was loaded. Every READ may use it; no WRITE may,
     * because a write decides what to store against what is current NOW — and `writeSlug()`
     * re-asks inside its retry loop precisely because a concurrent writer may have moved the
     * row since the previous attempt. Answering that from a cached collection would make the
     * retry loop consult the same stale row forever.
     */
    private function currentSlugRow(?string $locale = null, bool $fresh = false): ?PolyslugSlug
    {
        $locale ??= $this->polyslugLocale();

        if (! $fresh && $this->relationLoaded('slugs')) {
            // sortByDesc('id')->first(), matching the query's `orderByDesc('id')` exactly.
            // Taking the first match instead would take the OLDEST current row and disagree
            // with every non-eager read — a divergence no correctness test would show,
            // because both answers are real slugs of the same model.
            return $this->currentSlugsInMemory($locale)->sortByDesc('id')->first();
        }

        return $this->slugs()
            ->where('locale', $locale)
            ->where('is_current', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The loaded relation, narrowed to the current rows of one locale.
     *
     * @return Collection<int, PolyslugSlug>
     */
    private function currentSlugsInMemory(?string $locale): Collection
    {
        /** @var Collection<int, PolyslugSlug> $slugs */
        $slugs = $this->getRelation('slugs');

        return $slugs->filter(
            fn (PolyslugSlug $slug): bool => $slug->is_current
                && ($locale === null || $slug->locale === $locale)
        );
    }

    private function polyslugConfig(): PolyslugConfig
    {
        return PolyslugConfigResolver::resolve($this);
    }

    private function polyslugEncoder(): IdentityEncoder
    {
        $config = $this->polyslugConfig();
        $instance = $config->encoder === null ? Container::getInstance()->make(IdentityEncoder::class) : Container::getInstance()->make($config->encoder);

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
        // The application's current locale, read the way the framework itself stores
        // it: Application::getLocale() returns config('app.locale'), and setLocale()
        // writes there — so this is the same value, not an approximation, and it needs
        // no Foundation helper.
        $locale = Container::getInstance()->make(ConfigRepository::class)->get('app.locale');

        return is_string($locale) ? $locale : '';
    }

    private function polyslugDefaultLocale(): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);
        $configured = $config->get('polyslug.locale.fallback_locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Same reasoning as above: getFallbackLocale() is config('app.fallback_locale').
        $fallback = $config->get('app.fallback_locale');

        return is_string($fallback) ? $fallback : '';
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
