<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Head\CurrentHead;
use Laravel\Head\HeadManager;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;
use Polyslug\Exceptions\MisconfiguredPolyslug;
use Polyslug\Polyslug;

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
            $head->robots(self::gatedRobotsDirective($model, $locale));
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
        $alternateLinks = [];

        foreach ($model->hreflangLinks($urlUsing) as $hreflang => $url) {
            $alternateLinks[Polyslug::hreflangCode($hreflang)] = $url;
        }

        $head->alternates($alternateLinks);

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
        $head->canonical($resolver->url($model, $locale));

        $openGraph = self::openGraphLocale($locale);

        if ($openGraph !== null) {
            $head->og(locale: $openGraph);
        }

        $alternates = [];

        foreach (array_keys($urls) as $each) {
            if ($each === $locale) {
                continue;
            }

            $alternate = self::openGraphLocale($each);

            if ($alternate !== null) {
                $alternates[] = $alternate;
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
     * The Open Graph form of a locale — language_TERRITORY — or null when there is none.
     *
     * NULL MEANS NO TAG, and that is the correction rather than a gap. A bare "de" is
     * outside the format, and a scraper that cannot parse the value does not read a
     * language from it: it falls back to its own default, which is usually en_US. So the
     * tag was never saying what it looked like it was saying, and a page with no tag is
     * in exactly the same position minus the claim.
     *
     * The territory stays un-invented, which is what it always was here: "en" is en_US to
     * one site and en_GB to another, and asserting either would announce a regional
     * variant nobody configured. What is new is that a consumer can now name the pairs,
     * under polyslug.open_graph.locale_map. A locale that already carries a territory —
     * "pt_BR", "de-AT" — needs no entry and only has its separator normalized.
     */
    private static function openGraphLocale(string $locale): ?string
    {
        $container = Container::getInstance();
        $map = $container->make(ConfigRepository::class)->get('polyslug.open_graph.locale_map', []);
        $mapped = is_array($map) ? ($map[$locale] ?? null) : null;

        $candidate = is_string($mapped) && $mapped !== ''
            ? str_replace('-', '_', $mapped)
            : str_replace('-', '_', $locale);

        // ISO 639 language, then an ISO 3166-1 alpha-2 or UN M.49 numeric region. Checked
        // rather than assumed, because a mapped value is a consumer's string too.
        return preg_match('/^[A-Za-z]{2,3}_([A-Za-z]{2}|[0-9]{3})$/D', $candidate) === 1
            ? $candidate
            : null;
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
    /**
     * The robots directive a gated model gets — normalized, and checked against the
     * one thing the gate is about.
     *
     * method_exists() rather than a method on the Sluggable contract, and that IS the
     * no-break story: polyslugRobotsDirective() lives on HasPolyslug, so an application
     * implementing the contract by hand has no such method and keeps the historical
     * `none`. Putting it on the contract would have broken exactly those applications —
     * the outcome this option was chosen to avoid.
     *
     * @return list<string>
     */
    private static function gatedRobotsDirective(Sluggable $model, ?string $locale): array
    {
        if (! method_exists($model, 'polyslugRobotsDirective')) {
            return ['none'];
        }

        $directives = self::normalizeDirectives($model->polyslugRobotsDirective($locale));
        $unknown = self::unknownDirectives($directives);

        // Checked BEFORE the noindex rule below, so a page whose directive is both misspelled
        // and non-restricting is reported for the reason that explains the other.
        if ($unknown !== []) {
            throw MisconfiguredPolyslug::robotsDirectiveIsNotVocabulary($model::class, $unknown);
        }

        // `noindex` and `none` are the only two values that keep a page out of the
        // index. Everything else — including nothing at all — contradicts the branch
        // this runs in, and would un-gate the page silently rather than loudly.
        if (array_intersect($directives, ['noindex', 'none']) === []) {
            throw MisconfiguredPolyslug::robotsDirectiveMustPreventIndexing($model::class, $directives);
        }

        return $directives;
    }

    /**
     * Directives that stand alone, exactly as the crawlers document them.
     *
     * @var list<string>
     */
    private const array ROBOTS_KEYWORDS = [
        // `index` and `follow` are the defaults and rarely need writing, but they ARE
        // directives -- this package's own MisconfiguredPolyslug message recommends
        // `['noindex', 'follow']`, so a list without them would refuse the advice it gives.
        'all', 'index', 'follow', 'noindex', 'nofollow', 'none', 'noarchive', 'nosnippet',
        'indexifembedded', 'notranslate', 'noimageindex', 'nositelinkssearchbox', 'nocache',
    ];

    /**
     * Directives that carry a value, and what a valid value looks like.
     *
     * @var array<string, non-empty-string>
     */
    private const array ROBOTS_VALUED = [
        'max-snippet' => '/^-?\d+$/D',
        'max-video-preview' => '/^-?\d+$/D',
        'max-image-preview' => '/^(none|standard|large)$/D',
        'unavailable_after' => '/^\S.*$/D',
    ];

    /**
     * Every token a crawler would recognize, or the ones it would not.
     *
     * A robots tag has no error channel: an unrecognized directive is dropped, the tag renders,
     * and the page behaves as though the directive was never written. `nofollw` for `nofollow`
     * therefore reads as a working restriction and is none — which is the worst shape a defect
     * can take on a tag whose whole job is to restrict.
     *
     * @param  list<string>  $directives
     * @return list<string>
     */
    private static function unknownDirectives(array $directives): array
    {
        $unknown = [];

        foreach ($directives as $directive) {
            if (in_array($directive, self::ROBOTS_KEYWORDS, true)) {
                continue;
            }

            // A valued directive is `name:value`, and the value is checked as well as the name:
            // `max-image-preview:huge` is as inert as a misspelled keyword.
            $name = str_contains($directive, ':') ? strstr($directive, ':', true) : null;
            $pattern = is_string($name) ? (self::ROBOTS_VALUED[$name] ?? null) : null;

            if ($pattern !== null && preg_match($pattern, substr($directive, strlen((string) $name) + 1)) === 1) {
                continue;
            }

            $unknown[] = $directive;
        }

        return $unknown;
    }

    /**
     * Accepts `'noindex, follow'` and `['noindex', 'follow']` alike, so the rendered
     * tag cannot depend on which spelling a consumer happened to type.
     *
     * Takes mixed rather than string|array on purpose: the method it reads is not on
     * the contract, so nothing constrains what a consumer returns from it. A value
     * that carries no usable token normalizes to [] and is refused by the caller —
     * the same outcome as an empty answer, which is the correct one.
     *
     * @return list<string>
     */
    private static function normalizeDirectives(mixed $directives): array
    {
        $tokens = match (true) {
            is_string($directives) => explode(',', $directives),
            is_array($directives) => $directives,
            default => [],
        };

        $normalized = [];

        foreach ($tokens as $token) {
            if (! is_string($token)) {
                continue;
            }

            $token = strtolower(trim($token));

            if ($token !== '') {
                $normalized[] = $token;
            }
        }

        return $normalized;
    }

    private static function activeLocale(): string
    {
        $locale = Container::getInstance()->make(ConfigRepository::class)->get('app.locale');

        return is_string($locale) ? $locale : '';
    }
}
