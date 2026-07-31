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
- **Multi-tenant / draft isolation is your job.** Override `polyslugResolveQuery(Builder): Builder` on the model to constrain which rows a slug may resolve to (tenant / published scope). It is enforced uniformly across bound routes, the polymorphic resolver, slug-only, and short links; without it, resolution can cross tenants.
- **One resolver for canonical AND hreflang.** Build the reciprocal set from the same URL resolver as your canonical URL via `$model->hreflangTags($resolver)` (or the `@polyslugHreflang($model, $resolver)` Blade directive) so they cannot drift apart.
- **Using `laravel/head`? Let it own the `<head>`.** `Head::polyslug($model)` writes the canonical URL, the hreflang set, `og:locale` and a `robots` directive for gated models — and nothing else. Use it INSTEAD of `@polyslugHreflang`, never alongside it, or the page carries two identical alternate sets. Note that `Head::canonical()` on its own falls back to the request URL, which is the stale one on any route without the `polyslug.canonical` middleware.
- **Pick the encoder for the threat model.** The default is `RandomTokenEncoder`, which is leak-free. Switching to `SqidsEncoder` buys shorter tokens at the cost of real information: it is reversible and exposes the primary key, creation order and growth rate. Choose per project via `polyslug.encoder` (or per model with `#[Polyslug(encoder: ...)]`).

### More features

Scoped uniqueness (`scope:`), per-locale slugs (`setSlug`), nested paths (`polyslugParent`),
slug-only URLs (`#[Polyslug(idLess: true)]`), native non-Latin slugs (`unicode: 'native'`),
`polyslug:sitemap`, `/go` short links (`shortLink()`), the `polyslug:doctor` diagnostic, and the
`InteractsWithPolyslug` test assertions. The `polyslug-development` skill has the full option
and configuration reference.
