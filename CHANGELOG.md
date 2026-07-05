# Changelog

All notable changes to `pushery/polyslug-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-07-05

### Added
- MySQL 8.4 support (the database Laravel Cloud runs alongside serverless PostgreSQL). The uniqueness guarantees are enforced natively on MySQL via generated key columns that mirror the functional partial unique index used on PostgreSQL/SQLite, and the full test suite now runs against all three engines.

### Changed
- The slug write completes each demote-and-insert in a transaction that always commits — skipping a slug a concurrent writer claimed with `insertOrIgnore` and restoring the current row in place — instead of relying on a caught duplicate-key error and a nested savepoint rollback. This keeps slug writes correct when a model is saved inside an outer transaction on MySQL, where a nested savepoint rollback is unreliable.

## [0.1.0] - 2026-07-05

Initial public release: polymorphic, multilingual routable identity for Eloquent —
leak-safe encoded IDs, self-healing canonical redirects, per-locale slugs + hreflang.

### Changed
- Slug generation no longer throws by default when a source has no sluggable characters (a CJK/emoji-only title). The new `emptyFallback: 'id-only'` (default) stores an empty slug — the URL becomes `_{id}` — so a save can never fail after commit; opt back into the previous behavior with `#[Polyslug(emptyFallback: 'throw')]`.

### Added
- Laravel Boost integration: ships an AI guideline (`resources/boost/guidelines/core.blade.php`) and a `polyslug-development` skill (`resources/boost/skills/polyslug-development/SKILL.md`) that Boost auto-loads on `boost:install`, so AI coding assistants get accurate Polyslug conventions and the full option reference.
- Recipes: a README section with worked per-use-case setups (multi-tenant SaaS, multilingual news, nested e-commerce categories, slug-only docs, enumeration-safe IDs, and short links).
- Slug-only URLs: `#[Polyslug(idLess: true)]` drops the `_{id}` suffix — the URL is the slug alone. Resolution is by slug: the current slug resolves directly, a superseded slug 301s to the current URL, and retired slugs stay reserved so an old URL can never be reassigned to a different model. The resolve-query gate still applies; the slug must be unique per (type, locale, scope).
- Short links: `$model->shortLink()` mints a stable token, and the shipped `Polyslug\Http\Controllers\ShortLinkController` (route it at `/go/{token}`) 301s it to the model's current canonical URL — so a printed/QR link survives slug renames. Uses the bound `PolyslugUrlResolver`.
- Nested (hierarchical) slugs: override `polyslugParent()` to compose ancestor slugs into the route-key path (`/electronics/phones/iphone_TOKEN`). Paths are computed from ancestors' current slugs, so a rename/reparent self-heals via the canonical redirect (no cascade or stored path); scope on the parent key gives per-parent uniqueness; recursion is depth-bounded against cycles.
- Native (non-Latin) slugs: `#[Polyslug(unicode: 'native')]` preserves Unicode letters/numbers (Chinese, Cyrillic, Greek, accented Latin, …) instead of ASCII-transliterating, lower-cased at generation so the case-insensitive unique index is consistent across PostgreSQL and SQLite (assumes NFC-normalized input).
- Diagnostics: `php artisan polyslug:doctor` verifies the encoder config (encoder + legacy decoders implement `IdentityEncoder`) and that the uniqueness-guaranteeing indexes exist.
- Per-model encoder options: `#[Polyslug(encoderOptions: ['alphabet' => '…', 'min_length' => 12])]` gives a model its own `SqidsEncoder` token space (distinct from the global one and from other models).
- Encoder migration: `polyslug.legacy_decoders` lists previous encoders to try when the current one can't decode a token, so switching `polyslug.encoder` doesn't break existing URLs — they resolve via the legacy decoder and 301 to the new format.
- `RandomTokenEncoder`: a leak-free encoder mapping each key to an unguessable random token in the `polyslug_tokens` table — hides row count, order, and value for integer-keyed, enumeration-sensitive models.
- Sitemap generator: `polyslug:sitemap` streams all registered sluggable models into an XML sitemap with reciprocal `hreflang` alternates, using a bound `Polyslug\Contracts\PolyslugUrlResolver` and honoring `polyslugIsRoutable()`.
- Test assertions: the `Polyslug\Testing\InteractsWithPolyslug` trait adds `assertSlugRedirects`, `assertHasCurrentSlug`, `assertSlugResolves`, and `assertSlugNotResolvable` for consumers' test suites.
- Routing & Blade helpers: the `Route::polyslug($uri, $action)` macro registers a route with `SubstituteBindings` + `polyslug.canonical` in the correct order, and the `@polyslugHreflang($model, $resolver)` Blade directive renders the hreflang tags.
- Queued backfill: `polyslug:backfill --queue [--chunk=N]` dispatches chunked `Polyslug\Jobs\BackfillSlugsJob` jobs across queue workers for large tables, instead of one synchronous run.
- Redirect analytics: with `polyslug.analytics.enabled`, the canonical middleware dispatches a `Polyslug\Events\SlugRedirected` event on each self-heal (requested key, canonical URL, model, locale, status) — a fire-and-forget hook for link-rot metrics or CDN purging.
- Soft-delete slug release: `#[Polyslug(onDelete: 'release')]` frees a slug for reuse when its model is soft-deleted (default `'keep'` reserves it); a hard/force delete always cascades the slug rows so none are orphaned.
- Gone & superseded content: `polyslugSupersededBy()` 301s a model's URL to a successor (discontinued → replacement, preserving link equity), and `polyslugIsGone()` returns a configurable 410 (`polyslug.gone.status`) for permanently-removed content — both honored by the canonical middleware ahead of same-model self-heal.
- App-wide reserved slugs: `polyslug.reserved.global` is merged with each model's `reserved` list, so generated slugs never shadow sensitive routes (login, admin, api, …).
- Route-shadow guard: `polyslug.reserved.from_routes` seeds the reserved list from every registered route path, so a generated slug can never collide with a real route.
- Dynamic per-model configuration: implement `Polyslug\Contracts\ConfiguresPolyslug` and return a `PolyslugConfig` from `polyslug()` to compute slug rules at runtime (per-tenant reserved words, per-environment encoder, …) — resolved fresh and overriding the `#[Polyslug]` attribute. Fixes the previously documented-but-inert `polyslug()` override.
- Resolution visibility gate: override `polyslugResolveQuery()` (provided by `HasPolyslug`) to constrain which rows a slug may resolve to (tenant / published scope), enforced uniformly across bound routes and the polymorphic resolver — a model outside the scope resolves to a `404` indistinguishable from a nonexistent one (no existence oracle). `Sluggable::polyslugIsRoutable()` keeps unpublished models/locales out of hreflang sets and sitemaps.
- Locale-explicit routing: `polyslug.locale.source = 'route'` makes the canonical-redirect middleware use the `{locale}` route segment (instead of the ambient app locale), preventing wrong-language 301 loops on `/{locale}/…` routes. New `Sluggable::polyslugRouteKeyForLocale()` builds a route key for an explicit locale (safe in CLI/queues), and `polyslug.locale.missing` (`fallback`|`id-only`) controls the key when a locale has no slug.
- Concurrency-safe slug writes: the demote-old + insert-new steps run in a single transaction and retry (up to `polyslug.write.max_attempts`, default 5) when a concurrent writer claims the slug, and a new partial unique index guarantees exactly one current slug per (type, id, locale, scope). Exhausting the retries throws `Polyslug\Exceptions\CouldNotWriteSlug`.
- Per-model encoder override: `#[Polyslug(encoder: UuidEncoder::class)]` overrides the global identity encoder for a single model.
- Expanded README into a full feature overview with a quick-start and a "how it works" section.

### Fixed
- `Polyslug::isValidSlug()` now rejects slugs containing a trailing newline.
- `RawIdEncoder` rejects non-canonical leading-zero tokens (e.g. `007`), so each record has a single canonical URL — consistent with `SqidsEncoder`.
