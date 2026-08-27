<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Head\CurrentHead;
use Laravel\Head\HeadManager;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;

/**
 * Runtime target of the Head::polyslug() macro, registered only when the host has
 * laravel/head installed. Kept as a plain method so the macro stays a simple,
 * analyzable call rather than inline logic — the same shape as PolyslugBlade.
 *
 * It writes ONLY the four things Polyslug is the authority on: the canonical URL,
 * the reciprocal hreflang set, the Open Graph locale set, and whether the model may
 * be indexed at all. Title, description, Open Graph copy, cards and JSON-LD stay
 * laravel/head's job — this bridge never touches them.
 */
final class PolyslugHead
{
    public static function apply(HeadManager $head, Sluggable $model, ?string $locale = null): HeadManager
    {
        $locale ??= self::activeLocale();

        // A model the gate keeps out of hreflang sets and sitemaps must not be indexed
        // either. It can still RENDER — for its owner, a preview link, a draft reviewer
        // — and that is exactly the leak: without this, one shared URL puts a gated page
        // into the index. Applied first because it needs no resolver.
        if (! $model->polyslugIsRoutable($locale)) {
            $head->hiddenFromRobots();
        }

        $container = Container::getInstance();

        // An unbound resolver means the host has not told the package what its URLs look
        // like. Saying nothing about URLs is then the only honest answer — the same
        // stance ShortLinkController takes when it 404s rather than guessing a target.
        if (! $container->bound(PolyslugUrlResolver::class)) {
            return $head;
        }

        $resolver = $container->make(PolyslugUrlResolver::class);

        // The $routeKey argument is deliberately unused: the resolver derives the key
        // from the model and locale itself (its contract says so). Routing every URL
        // through the one resolver is what makes canonical, hreflang and the sitemap
        // unable to disagree about WHICH ADDRESS a record has.
        //
        // Not about its FORM, and the difference is measurable rather than pedantic:
        // laravel/head normalizes the canonical it is handed — Canonical::render() runs
        // normalizeUrl() on an explicitly passed URL too, defaulting to forceHttps: true
        // and trailingSlash: false, and a host can flip either through Head::defaults().
        // alternates() emits its hrefs verbatim, and polyslug:sitemap never sees
        // laravel/head at all. A resolver that returns http:// or a trailing slash
        // therefore yields a canonical that differs in form from the SELF-referencing
        // hreflang for the same page.
        //
        // Left as it is rather than "fixed": matching it would mean reimplementing
        // Canonical::normalizeUrl inside a package that must not depend on laravel/head,
        // and it still could not reach the sitemap command. A resolver built on route()
        // or url() over https — which is what an application in production has — produces
        // no divergence at all. The honest move is to say so, and to pin it in a test so
        // the claim cannot quietly widen again.
        $urlUsing = static fn (string $each, string $routeKey): string => $resolver->url($model, $each);

        $urls = $model->polyslugUrls($urlUsing);

        if ($urls === []) {
            return $head;
        }

        // The alternates describe the OTHER language versions, which stay useful even on
        // a page this model may not be indexed under — so they are written first and
        // unconditionally. hreflangLinks() resolves the set a SECOND time, and that is not
        // free: measured on a three-locale model it is seven more database reads, not "a few
        // route() calls". It is still the right trade against reimplementing the trait's
        // private x-default rule out here just to reuse $urls — and eager-loading the `slugs`
        // relation removes the cost entirely, because both passes then read the same loaded
        // collection instead of querying.
        $head->alternates($model->hreflangLinks($urlUsing));

        // Say nothing about THIS locale's URL when the model is not routable in it. The
        // robots directive above already hides the page; naming its URL in a canonical
        // tag would advertise the very address being withheld, and would contradict an
        // alternates set that deliberately omits it.
        if (! array_key_exists($locale, $urls)) {
            return $head;
        }

        // laravel/head's own canonical() falls back to the REQUEST url. On a route
        // without the polyslug.canonical middleware a stale slug renders 200, and that
        // fallback then declares the stale URL canonical. The resolver knows the current
        // one. Note it carries no query string, matching canonical() semantics — unlike
        // the middleware's redirect target, which preserves the query on purpose.
        $head->canonical($resolver->url($model, $locale))
            ->og(locale: self::openGraphLocale($locale));

        $alternates = [];

        foreach (array_keys($urls) as $each) {
            if ($each !== $locale) {
                $alternates[] = self::openGraphLocale($each);
            }
        }

        if ($alternates !== []) {
            // NOT $head->meta('og:locale:alternate', …) once per locale, which is what
            // this did until it was measured: MetaTags keys by attribute|key|media, so
            // every call after the first REPLACED its predecessor and a four-locale page
            // shipped one alternate. Nothing was red — every arm here ran two locales,
            // which is the one count at which a collision cannot be seen.
            //
            // CurrentHead is the request-scoped store HeadManager::headData() itself
            // reads; going through the container rather than the manager keeps this a
            // public-API call, where headData() is protected.
            $container->make(CurrentHead::class)
                ->data()
                ->overlayBuilder(new PolyslugOpenGraphLocales($alternates));
        }

        return $head;
    }

    /**
     * Open Graph writes a locale as language_TERRITORY. Only the SEPARATOR is
     * normalized here: a bare "de" stays "de" instead of becoming "de_DE", because the
     * territory is not ours to invent — guessing it would assert a regional variant
     * nobody configured, on a tag that social crawlers read literally.
     */
    private static function openGraphLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }

    /**
     * The application's current locale, read the way the framework itself stores it —
     * the same reasoning (and the same one line) as HasPolyslug::polyslugLocale(),
     * which is private and stays that way: an optional integration is a poor reason to
     * widen a shipped trait's public surface.
     *
     * On a /{locale}/... route, pass the locale explicitly instead of relying on this.
     * That is the same rule polyslugRouteKeyForLocale() exists for.
     */
    private static function activeLocale(): string
    {
        $locale = Container::getInstance()->make(ConfigRepository::class)->get('app.locale');

        return is_string($locale) ? $locale : '';
    }
}
