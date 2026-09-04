## Polyslug

Polyslug gives Eloquent models polymorphic, multilingual **routable identity**: pretty URLs
that are safe to expose (leak-free encoded IDs), safe to rename (self-healing 301s), and
correct across locales (per-locale slugs + reciprocal hreflang). A route key is
`{slug}_{encodedId}`; route-model binding resolves the opaque **id**, never the slug — so an
outdated or mistyped slug still finds the model, then redirects to the canonical URL.

### Making a model sluggable

Add the `#[Polyslug]` attribute, the `HasPolyslug` trait, and implement `Sluggable`. The
`polyslug_slugs` migration ships automatically, so `php artisan migrate` is enough. A slug is
generated on save; changing the source supersedes the old slug and keeps it as history.

@verbatim
<code-snippet name="Sluggable model" lang="php">
use Polyslug\Attributes\Polyslug;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Contracts\Sluggable;

#[Polyslug(source: 'title')]
class Page extends Model implements Sluggable
{
    use HasPolyslug;
}
</code-snippet>
@endverbatim

Route it with the `Route::polyslug()` macro, which wires `SubstituteBindings` +
`polyslug.canonical` in the correct order (a mis-ordered stack silently disables self-heal):

@verbatim
<code-snippet name="Routing" lang="php">
Route::polyslug('/pages/{page}', [PageController::class, 'show']);
// route('pages.show', $page) => /pages/my-title_aB3xK ; a stale slug 301s to the canonical URL.
</code-snippet>
@endverbatim

### Conventions that matter

- **Never treat the slug as the identifier.** Binding decodes the id; the slug is cosmetic and free to change.
- **The redirect waits for your authorization.** `polyslug.canonical` runs the route action first and replaces only a successful response, so an `abort(403)`/`abort(404)` reaches the client with no `Location` header — which matters because that header carries the resolved row's slug, usually its title. Do NOT rely on middleware order for this: `->middleware('can:...')` appended to `Route::polyslug()` still runs after it. The trade is that a request ending in a redirect executes the action and discards the response.
- **Eager-load `slugs` when rendering lists, and preload the tokens.** `Page::query()->with('slugs')->paginate()` plus `Page::polyslugPreload($pages)` makes every link, hreflang set and `<head>` on the page free of per-row queries. The preload is a no-op on a query-free encoder, so write it unconditionally — and also on a custom store-backed one until it implements `BulkIdentityEncoder`, which is how an encoder opts in. Writes always re-read the current row, and `slugHistory()` still queries — history lives in the non-current rows.
- **Multi-tenant / draft isolation is your job.** Override `polyslugResolveQuery(Builder): Builder` on the model to constrain which rows a slug may resolve to (tenant / published scope). It is enforced uniformly across bound routes, the polymorphic resolver, slug-only, and short links; without it, resolution can cross tenants.
- **A supersede successor is gated for you — do not filter inside `polyslugSupersededBy()`.** The successor arrives as a return value, not from a resolution, so Polyslug re-resolves it through `polyslugResolveQuery()` before rendering its route key into `Location`; one the requester may not see produces no redirect at all. Putting that check in `polyslugSupersededBy()` would hide a security property in a method whose name promises none. For any other model you obtained outside a resolution path and are about to disclose, call `polyslugResolveSelf()`.
- **Reserved words only ever ADD, until you say otherwise.** `reserved.global` + the model's own list + (with `from_routes`) every route's first segment all apply, and a hit is not refused — it is silently suffixed, so a record legitimately named "API" becomes `api-2`. On a model behind a constructive prefix (`/@owner/repo`) a slug cannot shadow a route at all, so every reservation there is a false positive: override `polyslugReservedWords(array $inherited): array` to filter or return `[]`.
- **One resolver for canonical AND hreflang.** Build the reciprocal set from the same URL resolver as your canonical URL via `$model->hreflangTags($resolver)` (or the `@polyslugHreflang($model, $resolver)` Blade directive) so they cannot drift apart.
- **Mirroring a handle that is case-preserving AND case-insensitive? `preserveCase: true`.** A slug is folded when generated, so `Octo-Org` is stored as `octo-org` and the original writing is gone from every URL built off the route key. The flag stores it verbatim and changes nothing else: the unique index and all three read paths already compare `lower(slug)`, so `Octo-Org` and `octo-org` remain one name and both still resolve. Pair it with `idLess: true`, which is where the case actually reaches the URL. It is refused together with `unicode: 'native'`.
- **Serving one slug under several addresses? Implement `ProvidesAddressLocales`.** The hreflang set and `polyslug:sitemap` are both built from `slugLocales()` — the locales that hold slug text. A project that pins each slug to one locale and still routes under a locale prefix (`/u/lena` and `/de/u/lena`) has one entry there forever, so the second address is announced in no sitemap and no hreflang set, and nothing goes red. Declaring `polyslugAddressLocales(): array` fixes both, because both read the same list. Opt-in: without the interface nothing changes.
- **Using `laravel/head`? Let it own the `<head>`.** `Head::polyslug($model)` writes the canonical URL, the hreflang set, `og:locale` plus one `og:locale:alternate` per other locale, and a `robots` directive for gated models — and nothing else. Use it INSTEAD of `@polyslugHreflang`, never alongside it, or the page carries two identical alternate sets. Note that `Head::canonical()` on its own falls back to the request URL, which is the stale one on any route without the `polyslug.canonical` middleware. It needs Laravel 13.17+, which is `laravel/head`'s own floor — Polyslug itself needs only 13.0.
- **Pick the encoder for the threat model.** The default is `RandomTokenEncoder`, which is leak-free. `SequentialTokenEncoder` hands out the shortest token not yet taken (`0`, `1`, … `z`, then `00`) — the shortest URL there is, and completely predictable, so the set can be walked and the token reports how many records exist. `SqidsEncoder` buys shorter tokens at the cost of real information: it is reversible and exposes the primary key, creation order and growth rate. Choose per project via `polyslug.encoder` (or per model with `#[Polyslug(encoder: ...)]`).
- **`maxLength` shortens the SLUG, never the token.** This is the most common wrong guess about a long URL: the `_{token}` after the slug has its own length, set by `polyslug.random_token.length` (default 16) or per model with `#[Polyslug(encoderOptions: ['length' => 8])]`. That length is a FLOOR — a width whose space fills up widens by a character rather than failing to issue a URL — so a short one is safe to pick. On a `slugless` model `maxLength` is rejected outright, because there is no slug to trim.
- **Want just the token? `#[Polyslug(slugless: true)]`.** The URL becomes the token alone (`/lists/k3f9dlq7`), with no slug, no delimiter and no `source` to declare — so renaming the record can never change its URL. It is the mirror image of `idLess` and setting both is refused. URLs published before the switch keep resolving and `301` to the short form.
- **Short links, sitemaps and canonical tags all need one class you write: `PolyslugUrlResolver`.** One method, model+locale → absolute URL, bound once in a service provider — the package cannot know your routes. It is the most common thing to be missing, and two of the three fail SILENTLY without it: every `/go` link returns 404 (deliberately identical to an unknown token) and the head tags are simply not written. `polyslug:doctor` reports the binding. Inside it use `polyslugRouteKeyForLocale($locale)`, never `getRouteKey()`, which answers for the current locale only.
- **The `/go` short link has its own token space.** `polyslug.short_links` takes its own `scheme` (`random` or `sequential`), `length` and `alphabet`, separately from the identity token — a printed or spoken link wants a different trade from the one inside every URL. Bind `Polyslug\Contracts\TokenScheme` to replace the scheme entirely.
- **On a `reclaimActive` model, a backfill must SEED, not claim.** `setSlug()`/`polyslugSync()` take a name another record still holds — correct for a webhook, where the source already handed it over. `seedSlug()`/`polyslugSeed()` let the holder keep it — correct for a backfill, where two existing records wanting one name is a conflict in the data, not a handover, and taking there decides ownership by row order while nobody finds out. `polyslug:backfill` seeds. Identical on a model that is not `reclaimActive`, so seeding is safe to use unconditionally.
- **Run `polyslug:doctor` after changing any token setting.** It refuses an unbuildable length or alphabet up front — otherwise that refusal first arrives while a URL is being rendered, in production — and reports how full each token width is, so a short setting that is running out is visible before it runs out.

### More features

Scoped uniqueness (`scope:`), per-locale slugs (`setSlug`), nested paths (`polyslugParent`),
slug-only URLs (`#[Polyslug(idLess: true)]`), token-only URLs (`#[Polyslug(slugless: true)]`),
name handover for mirrored registries
(`reclaim:` / `reclaimActive:` + the `SlugReclaimed` event), native non-Latin slugs (`unicode: 'native'`),
`polyslug:sitemap`, `/go` short links (`shortLink()`), the `polyslug:doctor` diagnostic, and the
`InteractsWithPolyslug` test assertions. The `polyslug-development` skill has the full option
and configuration reference.
