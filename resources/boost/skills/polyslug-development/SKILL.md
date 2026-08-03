---
name: polyslug-development
description: Build and work with Polyslug — polymorphic, multilingual routable slugs for Eloquent. Covers the #[Polyslug] attribute and every option, leak-safe identity encoders, per-locale slugs and hreflang, self-healing canonical redirects, multi-tenant resolution gating, nested and slug-only URLs, sitemaps, /go short links, and testing. Use when adding slugs to a model, routing sluggable models, or configuring any Polyslug behavior.
---

# Polyslug Development

## When to use this skill

Use this skill when making an Eloquent model routable with a pretty URL, when routing or
resolving sluggable models, when adding multilingual/hreflang URLs, or when configuring any
Polyslug behavior (encoders, scoping, nesting, slug-only mode, sitemaps, short links).

## Core model

A route key is two parts joined by an underscore: `{slug}_{encodedId}`. The **slug** is
human-readable, per-locale, and free to change. The **encoded id** is an opaque token
(produced by an `IdentityEncoder` from the primary key) that actually resolves the model.
Route-model binding decodes the **id**, so a stale/wrong slug still resolves — then the
`polyslug.canonical` middleware 301s it to the current canonical URL. Every slug a model has
ever had is kept in `polyslug_slugs` (current row flagged `is_current`, the rest as history),
so no published URL ever dies.

## Making a model sluggable

Add the attribute, the trait, and the interface. The `polyslug_slugs` migration is registered
automatically (`php artisan migrate`). Scaffold with `php artisan make:polyslug Page`.

```php
use Polyslug\Attributes\Polyslug;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Contracts\Sluggable;

#[Polyslug(source: 'title')]
class Page extends Model implements Sluggable
{
    use HasPolyslug;
}
```

## `#[Polyslug]` attribute options

| Option | Default | Meaning |
| --- | --- | --- |
| `source` | — | Column(s) the slug is built from (`string` or `array`; arrays join with a space). |
| `separator` | `'-'` | Word separator inside the slug. |
| `transliterate` | `Simple` | `TransliterationProfile::Simple` (ü→u) or `Din` (ü→ue). |
| `maxLength` | `null` | Trim to at most N characters (never mid-separator). |
| `unique` | `true` | Append `-2`, `-3`, … on a collision. |
| `scope` | `null` | Column(s) that scope uniqueness (e.g. `tenant_id`, `parent_id`). |
| `reserved` | `[]` | Slugs that may never be assigned. |
| `immutable` | `false` | Freeze the slug — never regenerate on source change. |
| `encoder` | `null` | Per-model `IdentityEncoder` class overriding the global one. |
| `onDelete` | `'keep'` | On soft-delete: `keep` reserves the slug; `release` frees it. Hard/force delete always cascades slug rows. |
| `emptyFallback` | `'id-only'` | A source with no sluggable characters (CJK/emoji-only): `id-only` stores an empty slug (URL is `_{id}`) so the save never fails; `throw` raises `CouldNotGenerateSlug`. |
| `encoderOptions` | `[]` | Per-model `SqidsEncoder` options (`alphabet`, `min_length`) — a dedicated token space. Ignored unless the effective encoder is Sqids. |
| `unicode` | `'ascii'` | `native` keeps Unicode letters/numbers (non-Latin markets); slugs are lower-cased at generation so the case-insensitive unique index is consistent on PostgreSQL and SQLite. |
| `idLess` | `false` | Drop the `_{encodedId}` suffix — the URL is the slug alone; resolution is by slug (see Slug-only). |

For dynamic (per-tenant/runtime) config, implement `Polyslug\Contracts\ConfiguresPolyslug` and
return a `PolyslugConfig` from `polyslug()` — it overrides the attribute.

## `config/polyslug.php`

`encoder`, `sqids.{alphabet,min_length}`, `legacy_decoders` (previous encoders to try on a
decode miss — encoder migration), `write.max_attempts`, `locale.{source,route_param,missing,fallback_locale}`,
`reserved.{global,from_routes}`, `redirect.status` (the self-heal status, 301 by default),
`gone.{status,redirect_status}`, `analytics.enabled`,
`sitemap.types`, `types` (polymorphic registry).

## Leak-safe identity encoders

`IdentityEncoder::encode(int|string): string` / `decode(string): int|string|null` (null → 404,
never a fuzzy match). Built in: `RandomTokenEncoder` (**the default** — unguessable random
token in `polyslug_tokens`, leak-free for integer keys), `SqidsEncoder` (obfuscation, not
security: reversible, and it leaks the primary key, creation order and growth rate),
`UuidEncoder`, `UlidEncoder` (leaks creation time), `RawIdEncoder` (raw PK — internal only).
Non-canonical tokens (wrong length, leading zeros, re-encoded alias) resolve to a clean 404, so
each record has exactly one canonical URL. Migrate encoders without breaking links by listing
the previous encoder in `polyslug.legacy_decoders`.

A custom encoder that hits a store per key should also implement `BulkIdentityEncoder`
(`encodeMany(list<int|string> $ids): array<string, string>` — a token per key, keyed by the
**string** form of the id, so a caller can look one up with `(string) $model->getKey()`
regardless of whether the key was an int) — a **second** interface
extending `IdentityEncoder`, so an encoder you already wrote keeps satisfying its contract
untouched. It is what makes `polyslugPreload()` do anything: without it the preload skips your
encoder silently. Its result must equal encoding one key at a time — same tokens, same
collision handling — so it optimizes round trips and never the guarantees.

## Routing & self-healing

```php
Route::polyslug('/pages/{page}', [PageController::class, 'show']); // wires SubstituteBindings + polyslug.canonical in order
```

On a safe (GET/HEAD) request whose slug is stale, `polyslug.canonical` issues a redirect to the
current canonical URL. For `/{locale}/...` routes, set `polyslug.locale.source = 'route'` so the
middleware compares the route's `{locale}` segment (not the ambient app locale) and avoids
wrong-language 301 loops. `polyslugRouteKeyForLocale($locale)` builds a key for an explicit
locale (safe in queues/CLI).

**The application answers first.** The middleware works out what it would say, then runs the
route action and replaces only a **successful** response. If the action refuses — `abort(403)`,
`abort(404)`, or a returned non-2xx — that refusal reaches the client with no `Location` header.
This matters because the header of a canonical redirect carries the resolved row's slug, i.e.
usually its title, and a model on the open default `polyslugResolveQuery()` resolves any slug
to any row. Ordering `->middleware('can:...')` after the macro does NOT come earlier — Laravel's
priority sort leaves it behind `polyslug.canonical`. The cost: a request that ends in a redirect
runs the action first and discards its response, so a view counter counts it.

## Rendering lists: eager-load `slugs`

```php
$pages = Page::query()->with('slugs')->paginate();   // links now cost no query per row
```

`currentSlug()`, `polyslugRouteKey()`, `slugLocales()` and everything on top of them
(`polyslugUrls()`, `hreflangLinks()`, `hreflangTags()`, `sitemapAlternateTags()`,
`Head::polyslug()`) read the loaded collection. Writes never do — they always re-read the
current row — and `slugHistory()` keeps querying, because history lives in the non-current
rows a narrowed eager load would omit.

Add `Page::polyslugPreload($pages)` after the query to remove the remaining per-row token
read of the default store-backed encoder. It is a no-op on Sqids/UUID/ULID/raw-key, so write
it unconditionally — with both in place the page issues no query per row.

## Multi-tenant / draft isolation (required contract)

Override `polyslugResolveQuery()` to constrain which rows a slug may resolve to. Enforced across
bound routes, the polymorphic resolver, slug-only, and short links. A row outside the scope
resolves to a 404 indistinguishable from a nonexistent one (no existence oracle).

```php
public function polyslugResolveQuery(Builder $query): Builder
{
    return $query->where('tenant_id', tenant()->id)->where('published', true);
}
```

`polyslugIsRoutable(?string $locale = null): bool` keeps unpublished models/locales out of
hreflang sets and sitemaps.

## Multilingual & hreflang

One slug per locale; the reciprocal hreflang set is built from the SAME resolver as the
canonical URL, so they cannot drift.

```php
$page->setSlug('de', 'Hallo Welt');
$page->hreflangTags(fn (string $locale, string $key) => route('pages.show', [$locale, $key]));
```

Or in Blade: `@polyslugHreflang($page, $resolver)`.

## `laravel/head` (optional) {#laravel-head}

If `laravel/head` is installed, let it own the `<head>` instead of rendering tags yourself.
`Head::polyslug($model)` writes ONLY what Polyslug is the authority on — canonical URL (from
the bound `PolyslugUrlResolver`, not the request), the reciprocal hreflang set, `og:locale`
plus alternates, and `robots: none` when `polyslugIsRoutable()` is false. Title, description,
cards and JSON-LD stay yours.

```php
Head::polyslug($article)->title($article->title)->description($article->excerpt);
Head::polyslug($article, $request->route('locale')); // on a /{locale}/... route
```

Two rules. **Pick one hreflang path** — `Head::polyslug()` OR `@polyslugHreflang`, never both,
or the page carries two identical alternate sets. And **`Head::canonical()` alone is not
enough**: it falls back to the request URL, so on a route without `polyslug.canonical` a stale
slug renders and that tag names the outdated URL as canonical.

Requires a bound `PolyslugUrlResolver` for the URL tags; without one it writes only the robots
directive rather than guessing a URL shape.

## Nested (hierarchical) slugs

Override `polyslugParent()` to compose ancestor slugs into the path (`/electronics/phones/iphone_TOKEN`);
scope uniqueness on the parent key. Paths are computed from ancestors' current slugs, so a
rename/reparent self-heals via the canonical redirect (no cascade). Route with a catch-all:
`Route::polyslug('/{category}', ...)->where('category', '.*')`.

## Slug-only URLs (`idLess`)

`#[Polyslug(idLess: true)]` drops the id suffix. Resolution is by slug: current resolves
directly, a superseded slug 301s to the current URL, and retired slugs stay reserved so an old
URL can never point at a different model. The slug must be unique per `(type, locale, scope)`.

## Sitemaps & short links

- Bind `Polyslug\Contracts\PolyslugUrlResolver` (model+locale → absolute URL); it feeds both:
- `php artisan polyslug:sitemap --path=public/sitemap.xml` — streams all `polyslug.sitemap.types` with hreflang alternates, honoring `polyslugIsRoutable()`.
- `$model->shortLink()` + route `ShortLinkController` at `/go/{token}` — a stable token that 301s to the current canonical URL (survives renames).

## Other operations

- Gone/supersede: `polyslugSupersededBy()` 301s to a successor; `polyslugIsGone()` returns a configurable 410.
- Backfill: `php artisan polyslug:backfill "App\\Models\\Page" [--locale=de] [--queue] [--chunk=N]`.
  The model class is a REQUIRED argument, not an option — the command backfills one
  sluggable model at a time.
- Analytics: `polyslug.analytics.enabled` fires a `SlugRedirected` event on each self-heal.
- Diagnostics: `php artisan polyslug:doctor` checks the encoder config, the uniqueness
  indexes, and reports every model that still resolves through the open default
  `polyslugResolveQuery()` — the models on which any slug resolves to any row.

## Testing

Use the `InteractsWithPolyslug` trait in a test case:

```php
$this->assertHasCurrentSlug($page, 'hello-world');
$this->assertSlugResolves(Page::class, $page->getRouteKey(), $page->id);
$this->assertSlugRedirects('/pages/old-slug_TOKEN', '/pages/new-slug_TOKEN');
$this->assertSlugNotResolvable(Page::class, 'bad_token');
```

## Gotchas

- Add `polyslug.canonical` (or the `Route::polyslug` macro) or renames won't self-heal.
- Without `polyslugResolveQuery()`, a shared slug can resolve across tenants — always scope it in multi-tenant apps.
- The slug never identifies the model; binding uses the encoded id. A wrong slug still resolves, then redirects.
- `idLess` requires globally-unique slugs per scope; retired slugs stay reserved by design.
- Native Unicode mode assumes NFC-normalized input.
