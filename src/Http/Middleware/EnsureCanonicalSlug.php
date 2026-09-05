<?php

declare(strict_types=1);

namespace Polyslug\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;
use Polyslug\Contracts\Sluggable;
use Polyslug\Events\SlugRedirected;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Self-healing slugs: on a safe (GET/HEAD) request whose bound Sluggable model no
 * longer carries the requested slug, redirect to the canonical URL. Redirecting must
 * not happen in resolveRouteBinding (which may only return a model or null), so it
 * lives here. This middleware must run after SubstituteBindings so the model is bound.
 */
final class EnsureCanonicalSlug
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $this->isSafe($request) || ! $route instanceof Route) {
            return $next($request);
        }

        $locale = $this->requestLocale($route);

        // Gone / superseded content is terminal — it takes precedence over same-model self-heal.
        // Decided here, ANSWERED below: what this middleware would say is worked out before
        // the action runs, so the decision still sees the request as it arrived; saying it is
        // deferred until the application has had its turn.
        $gone = $this->isGone($route);
        $terminal = $gone ? null : $this->supersededRedirect($route, $locale);
        $stale = $gone || $terminal instanceof Response
            ? false
            : $this->hasStaleSlug($route, $locale) || $this->hasTrailingSlash($request);

        if (! $gone && ! $terminal instanceof Response && ! $stale) {
            return $next($request);
        }

        // THE APPLICATION ANSWERS FIRST, and this is the whole security property.
        //
        // Answering before it — which is what this middleware used to do — puts a Location
        // header carrying another row's canonical slug, i.e. usually its title, in front of
        // the application's own authorization. The default polyslugResolveQuery() is open,
        // so on a model whose author never narrowed it, any slug resolves to any row and the
        // redirect discloses it. Route binding cannot catch that: it runs through the same
        // gate, so a bound model has already passed whatever gate exists.
        //
        // Ordering the middleware differently does not fix it either. Route::polyslug() wires
        // [SubstituteBindings, polyslug.canonical] into the route and Laravel's priority sort
        // does not lift an unprioritized Authorize in front of them, so even a consumer who
        // correctly writes ->middleware('can:...') leaks; authorization inside the action has
        // no ordering escape at all. Deferring the answer is the only fix that protects a
        // consumer without asking anything of them.
        //
        // If the action throws — the common `abort_unless()` shape — this line propagates and
        // no redirect is ever built.
        $response = $next($request);

        // Only a SUCCESSFUL answer may be replaced. Anything else is the application's verdict
        // and is returned untouched: a refusal (4xx/5xx), and equally a redirect the action
        // issued itself, which this one has no business overwriting.
        if (! $response->isSuccessful()) {
            return $response;
        }

        if ($gone) {
            // Thrown directly rather than through abort(): that helper is a Foundation-only
            // global, and it does exactly this — Application::abort() throws an HttpException
            // for any non-404 status.
            throw new HttpException($this->configuredStatus('polyslug.gone.status', 410));
        }

        if ($terminal instanceof Response) {
            return $terminal;
        }

        $url = $this->canonicalUrl($request, $route, $locale);

        // NO LOOP GUARD HERE, and it was written and then removed rather than never considered.
        // A redirect to the address just requested is a loop a browser gives up on, and the way
        // to reach one would be a canonical path that itself ends in a slash. It cannot: Route
        // normalizes its URI through `trim($uri, '/')`, so `pages/{page}/` and `/pages/{page}/`
        // both store `pages/{page}` -- measured on both spellings. The guard was a branch no run
        // could enter, which the coverage floor is right to object to and which reads to the
        // next person like a case that happens.
        $status = $this->status();
        $this->recordRedirect($route, $locale, $url, $status);

        return Container::getInstance()->make(Redirector::class)->to($url, $status);
    }

    /**
     * A path with a trailing slash is a SECOND address for the same document.
     *
     * Nothing in the stack removes it: the router matches `/p/x/` against `/p/{page}` with the
     * slug parameter identical, so hasStaleSlug() sees nothing wrong and the page answers 200
     * at both addresses. Measured before this check existed -- `/p/{key}` and `/p/{key}/` both
     * 200, no Location header on either. That is precisely what a canonical redirect exists to
     * prevent, arriving through the one door it was not watching.
     */
    private function hasTrailingSlash(Request $request): bool
    {
        $path = $request->getPathInfo();

        // The site root is not a duplicate of anything: `/` IS the path, not `` with a slash.
        return $path !== '/' && str_ends_with($path, '/');
    }

    private function isSafe(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }

    private function hasStaleSlug(Route $route, string $locale): bool
    {
        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof Sluggable
                && is_string($requested = $route->originalParameter($name))
                && $requested !== $value->polyslugRouteKeyForLocale($locale)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalUrl(Request $request, Route $route, string $locale): string
    {
        // Rebuild each sluggable parameter for the REQUEST's locale (not the ambient app
        // locale), so a /{locale}/... URL redirects to that locale's slug, not another's.
        //
        $parameters = [];

        foreach ($this->declaredParameters($route) as $name => $value) {
            $parameters[$name] = $value instanceof Sluggable
                ? $value->polyslugRouteKeyForLocale($locale)
                : $value;
        }

        $url = Container::getInstance()->make(UrlGenerator::class)->toRoute($route, $parameters, true);
        $query = $request->getQueryString();

        return $query === null ? $url : $url.'?'.$query;
    }

    private function requestLocale(Route $route): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);

        if ($config->get('polyslug.locale.source') === 'route') {
            $param = $config->get('polyslug.locale.route_param', 'locale');
            $value = $route->parameter(is_string($param) ? $param : 'locale');

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        // The application's current locale, read the way the framework itself stores
        // it: Application::getLocale() returns config('app.locale'), and setLocale()
        // writes there — so this is the same value, not an approximation, and it needs
        // no Foundation helper.
        $locale = Container::getInstance()->make(ConfigRepository::class)->get('app.locale');

        return is_string($locale) ? $locale : '';
    }

    private function status(): int
    {
        $status = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.redirect.status', 301);

        return is_int($status) ? $status : 301;
    }

    /**
     * Whether any bound model has been permanently withdrawn.
     *
     * Split out of the supersede check rather than throwing from inside it, because the
     * two now happen at different times: the QUESTION is asked before the action runs,
     * the ANSWER is given after. A throw cannot be held, so it could not stay there —
     * and a 410 handed out ahead of the application's own refusal is a state oracle, it
     * separates "exists and was withdrawn" from "no such row" for a row the request may
     * not see.
     *
     * Gone wins over superseded: a withdrawn model is not redirected to a successor.
     */
    private function isGone(Route $route): bool
    {
        foreach ($route->parameters() as $value) {
            if ($value instanceof Sluggable && $value->polyslugIsGone()) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE SUCCESSOR GOES THROUGH THE GATE TOO, and that is not the same property 0.7.0 fixed.
     *
     * 0.7.0 reversed the middleware so a canonical redirect can no longer overtake the
     * application's authorization. That fixed the TIMING. It did not fix WHOSE row ends up in
     * the Location header, and for a supersede redirect those are two different rows.
     *
     * $value is gated: route binding resolved it through polyslugResolveQuery(), and the
     * application then answered 2xx for it. Neither holds for the successor. It arrives as a
     * return value from polyslugSupersededBy(), not from a resolution, and its route key —
     * usually its title — was rendered into a 301 without anyone asking whether the requester
     * may see it. A consumer whose gate is closed and whose action authorizes correctly still
     * leaked a foreign row's title, one hop beyond where the resolution gate reaches.
     *
     * Pushing the check into the consumer's polyslugSupersededBy() was rejected for the reason
     * 0.7.0 gave for the resolution gate: the method is named supersededBy, not
     * supersededByIfVisible, and a security property does not belong in a method whose
     * signature does not hint at it. Deferring the answer is the only fix that asks the
     * consumer for nothing.
     *
     * An invisible successor makes THIS PARAMETER produce no redirect — the loop simply moves
     * on, exactly as it does for a parameter that was never superseded. The application's own
     * response is then handed back untouched, and the self-heal check downstream still runs
     * against the model the request legitimately holds.
     */
    private function supersededRedirect(Route $route, string $locale): ?Response
    {
        foreach ($route->parameters() as $name => $value) {
            if (! $value instanceof Sluggable) {
                continue;
            }

            $successor = $value->polyslugSupersededBy();

            if (! $successor instanceof Sluggable) {
                continue;
            }

            // Resolved rather than merely checked: the URL is then built from the row the gate
            // returned, not from the instance the model handed over.
            $visible = $successor->polyslugResolveSelf();

            if (! $visible instanceof Sluggable) {
                continue;
            }

            // A successor that is this very record would redirect to the address just
            // requested, and the browser would follow that forever. It is treated as no
            // successor at all: the page is served, exactly as for a record nobody superseded.
            if ($visible instanceof Model && $value instanceof Model && $visible->is($value)) {
                continue;
            }

            return Container::getInstance()->make(Redirector::class)->to(
                $this->successorUrl($route, $name, $visible, $locale),
                $this->configuredStatus('polyslug.gone.redirect_status', 301),
            );
        }

        return null;
    }

    private function successorUrl(Route $route, string $supersededParam, Sluggable $successor, string $locale): string
    {
        $parameters = [];

        foreach ($this->declaredParameters($route) as $name => $value) {
            if ($name === $supersededParam) {
                $parameters[$name] = $successor->polyslugRouteKeyForLocale($locale);
            } elseif ($value instanceof Sluggable) {
                $parameters[$name] = $value->polyslugRouteKeyForLocale($locale);
            } else {
                $parameters[$name] = $value;
            }
        }

        return Container::getInstance()->make(UrlGenerator::class)->toRoute($route, $parameters, true);
    }

    /**
     * The bound route parameters the route's own URI actually DECLARES.
     *
     * `$route->parameters()` is not that list. Laravel's RouteParameterBinder folds every
     * route DEFAULT into the bound parameters, including keys the URI never mentions:
     *
     *     Route::get('pages/{page}', ...)->defaults('locale', 'en');
     *     // $route->parameters() === ['page' => <Page>, 'locale' => 'en']
     *
     * `UrlGenerator::toRoute()` cannot place `locale` in the path, so it appends it to the
     * QUERY STRING. Both URL builders in this middleware then emitted `/pages/canonical?locale=en`
     * — and where the request carried a query string of its own, `?locale=en?ref=news`, which
     * is not a valid URL at all.
     *
     * That is the exact opposite of this middleware's purpose. A canonical redirect is where
     * an address is declared binding; a parameter welded onto it creates a SECOND address for
     * the same page, for every renamed row at once.
     *
     * Filtering is lossless: a parameter the URI does not declare cannot contribute to the
     * path, it can only lengthen it. Locale resolution is untouched — `requestLocale()` reads
     * the same source earlier and independently.
     *
     * Used by BOTH builders on purpose: `successorUrl()` builds its URL the same way, so
     * filtering in only one of them would leave the other emitting the parameter.
     *
     * Keyed `array-key` rather than `string` because that is all the framework promises:
     * both `parameters()` and `parameterNames()` are annotated bare `array`. Route parameter
     * names are strings in practice, but claiming it here would be an assertion this code
     * cannot support, and neither caller needs it — both use the key as an array key and one
     * compares it against a string, which behaves correctly either way.
     *
     * @return array<array-key, mixed>
     */
    private function declaredParameters(Route $route): array
    {
        $declared = $route->parameterNames();
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            if (in_array($name, $declared, true)) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    private function configuredStatus(string $key, int $default): int
    {
        $status = Container::getInstance()->make(ConfigRepository::class)->get($key, $default);

        return is_int($status) ? $status : $default;
    }

    private function recordRedirect(Route $route, string $locale, string $url, int $status): void
    {
        if (Container::getInstance()->make(ConfigRepository::class)->get('polyslug.analytics.enabled') !== true) {
            return;
        }

        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof Sluggable
                && is_string($requested = $route->originalParameter($name))
                && $requested !== $value->polyslugRouteKeyForLocale($locale)) {
                Container::getInstance()->make(Dispatcher::class)
                    ->dispatch(new SlugRedirected($value, $requested, $url, $locale, $status));

                return;
            }
        }
    }
}
