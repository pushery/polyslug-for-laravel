# Changelog

All notable changes to `pushery/polyslug-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2026-07-26

### Fixed
- **`RandomTokenEncoder` no longer 500s on a concurrent first render.** `encode()` did a
  read-then-write against a unique index, so two requests rendering the same
  never-before-encoded model both missed the lookup and both inserted — the loser took a
  constraint violation, and because `encode()` runs on the URL-render path that surfaced
  as an intermittent 500 on a `GET`. The loser now adopts the winner's token, so both
  requests emit the same canonical URL. Proven against real PostgreSQL and MySQL 8.4.
- **The same fix survives MySQL's REPEATABLE READ.** A caller encoding inside
  `DB::transaction()` could not see the row it kept colliding with; the retry now reads
  `FOR UPDATE` after a lost attempt. PostgreSQL never exhibited this, which is why the
  proof runs on both engines.

### Changed
- **`RandomTokenEncoder` is the default encoder.** `SqidsEncoder` remains fully
  supported, but its token decodes straight back to the primary key — every URL leaked
  the key, the creation order and the growth rate. That is a trade worth making
  deliberately, not one you get by not deciding.
- `polyslug:doctor` now reports every registered type that never overrode
  `polyslugResolveQuery()`. Those models resolve any slug to any row — correct for public
  content, a silent authorization bypass for anything owner-scoped. It reports and still
  exits successfully: the check makes the choice visible, it does not make it.

### Removed
- **Breaking.** `routes/polyslug.php` and its `loadRoutesFrom()` call are gone. The file
  held only comments; `ShortLinkController` is mounted by the consuming application at a
  path of its choosing, as its own docblock and the `Sluggable` contract both describe.

### Upgrading from 0.4.x
If you **published** `config/polyslug.php`, nothing changes — your file still names the
encoder it always did. If you **did not**, you inherit `RandomTokenEncoder` and existing
URLs stop resolving. Either pin the old encoder, or take the migration and let old links
self-heal:

```php
'encoder'         => RandomTokenEncoder::class,
'legacy_decoders' => [SqidsEncoder::class],
```

Old URLs keep resolving through the legacy decoder and are `301`ed to the new format as
they are visited — no flag day, no broken bookmarks.

## [0.4.0] - 2026-07-26

### Removed
- **Breaking.** The package no longer ships views or translations, and the
  `polyslug-views` / `polyslug-lang` publish tags and the `polyslug::` view and
  translation namespaces are gone with them. Both directories held nothing but the
  generator's placeholders — a comment-only Blade file and seven copies of *"This is
  an example Polyslug translation string."* — and no shipped code ever resolved a
  translation key or rendered a view. Polyslug routes and resolves; it renders nothing
  and emits no user-facing text (its exception messages and console output address
  developers). If you published either tag, the published files were placeholders and
  can be deleted.

### Fixed
- **Publishing no longer fatals on a lean install.** `vendor:publish` resolved its
  targets through the `config_path()` / `database_path()` / `resource_path()` /
  `lang_path()` global helpers, which ship only with `laravel/framework` — a package
  this one does not require. They are gone, along with every other Foundation-only
  helper in shipped code (`app()`, `config()`, `abort()`, `event()`, `now()`), all now
  resolved through the container and the `illuminate/contracts` interfaces.
- **Published migrations sort correctly.** The bundled migration is published with
  `publishesMigrations()`, so its `0001_01_01_000000` ordering prefix is rewritten to
  the publish date. Previously it sorted before every migration the host application
  already had, and so ran before the tables it may reference existed.
- **The dependency declaration matches what the code uses.** `composer.json` required
  only `illuminate/contracts` and `illuminate/support` while the code used Eloquent,
  the router, HTTP, the console, Blade and the filesystem. Eight components are now
  declared: `collections`, `console`, `container`, `database`, `filesystem`, `http`,
  `routing` and `view`.

### Added
- `vendor:publish --tag=polyslug` publishes every resource group at once, alongside
  the existing per-group tags.

### Documentation
- The full documentation now lives at
  [docs.pushery.com/polyslug-for-laravel](https://docs.pushery.com/polyslug-for-laravel/),
  restructured into pages you can link to: installation, quick start, how it works, a page
  per feature, one per persona recipe, a reference section (configuration, attribute
  options, model API, commands, events, contracts, exceptions, database) and guides for
  testing, diagnostics and troubleshooting. Nothing was dropped in the move — the
  reference and database pages document surface the README never covered. The README is
  now a short showcase that links there.

## [0.3.0] - 2026-07-13

### Added
- The package now ships its translation set in all seven default locales — **de, en,
  es, fr, it, nl, pt** — under `lang/*`. A boot test enforces that every one of the
  seven has its own `messages.php` and resolves its strings in its own words (no silent
  English fallback), so the locale coverage can never regress.

## [0.2.1] - 2026-07-11

### Documentation
- The README "Recipes" section is now a complete cookbook covering all ten app personas — added Social/UGC (shared slugs via `unique: false`), Marketplace (per-seller scope + a resolution gate), Headless CMS (the polymorphic type registry and one catch-all route), Government/enterprise (immutable slugs, 410 Gone, and supersede redirects), Events/ticketing (QR short links that survive renames), and Real-estate/geo (nested location paths). Every recipe was verified against the source.

## [0.2.0] - 2026-07-11

### Added
- `#[Polyslug(unique: false)]` now lets non-idLess records **share** a slug instead of failing. A non-idLess URL is `slug_id` and resolves by the encoded id, so duplicate slugs are unambiguous. Such rows are written with a new `enforce_unique = false` flag and excluded from the slug-uniqueness index, while the one-current-row guarantee is untouched — enforced identically on SQLite, PostgreSQL (partial-index predicate) and MySQL 8.4 (generated key column). The bundled `0002_…_add_enforce_unique_to_polyslug_slugs` migration adds the column and rebuilds the index; existing slugs keep their uniqueness because the column defaults to `true`.

### Changed
- Combining `idLess: true` with `unique: false` is now rejected at configuration time with the new `Polyslug\Exceptions\MisconfiguredPolyslug`. An idLess URL is the slug alone, so an idLess model resolves *by* its slug and the slug must stay unique.

### Removed
- `Polyslug\Exceptions\SlugCollision` (added in v0.1.4). With `unique: false` now allowing shared slugs for non-idLess models, there is no collision to fail on — the v0.1.4 fail-fast was the honest interim; this is the full behaviour.

## [0.1.4] - 2026-07-11

### Fixed
- `#[Polyslug(unique: false)]` now fails fast with a dedicated `Polyslug\Exceptions\SlugCollision` when the generated slug already belongs to another model in the same `(type, locale, scope)`, instead of looping into the generic `CouldNotWriteSlug` (which reads like a transient write conflict and misled you into suspecting concurrency). `unique: false` disables the numeric `-2`/`-3` suffix, so the slug must be collision-free within its scope — the new exception says exactly that, names the offending slug, and is thrown at generation before any write attempt. Choose a distinct source, add a `scope` that separates the records, or drop `unique: false` to restore the suffix. Verified on SQLite, PostgreSQL, and MySQL 8.4.

## [0.1.3] - 2026-07-11

### Changed
- The cross-engine tests now run as dedicated `Postgres` and `MySql` test suites in a single `composer test:database` pass, instead of re-running the whole suite once per engine via `DB_CONNECTION`. Each suite points the default connection at a real server, probes it first, and **skips gracefully** when it is unreachable so a bare checkout stays green; exporting `REQUIRE_DB_TESTS=1` turns a missing engine into a hard failure so a green run really did prove both. The suites assert the one-current-slug and case-insensitive-slug guarantees are enforced identically on PostgreSQL (functional partial index) and MySQL 8.4 (virtual generated key columns). The GitHub Actions test job gains PostgreSQL 17 and MySQL 8.4 service containers so the parity is enforced there too. Point the suites at your servers with `PG_TEST_*` / `MYSQL_TEST_*` (`MYSQL_TEST_PORT=3308` for Herd's MySQL 8.4).

### Fixed
- The README PHP-version badge rendered "not found": the upstream `packagist/php-v` shields.io endpoint returns empty for every package right now. It now reads from `packagist/dependency-v/pushery/polyslug-for-laravel/php`, which shows the required PHP version from the published `composer.json`. Badge only — no code or dependency change.

## [0.1.2] - 2026-07-05

### Fixed
- The `config/polyslug.php` sitemap comment told you to bind `Polyslug\Contracts\SitemapUrlResolver`, a class that does not exist — the contract is `Polyslug\Contracts\PolyslugUrlResolver` (as the README and the `polyslug:sitemap` command already stated). Following the config comment would have bound a non-existent class.

### Documentation
- The routing examples now name their route (`->name('pages.show')`), so the `route('pages.show', $page)` calls in the README run as written when copied verbatim.

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
