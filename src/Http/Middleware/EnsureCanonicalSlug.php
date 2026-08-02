<?php

declare(strict_types=1);

namespace Polyslug\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
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
        $stale = $gone || $terminal instanceof Response ? false : $this->hasStaleSlug($route, $locale);

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
        $status = $this->status();
        $this->recordRedirect($route, $locale, $url, $status);

        return Container::getInstance()->make(Redirector::class)->to($url, $status);
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
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
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

    private function supersededRedirect(Route $route, string $locale): ?Response
    {
        foreach ($route->parameters() as $name => $value) {
            if (! $value instanceof Sluggable) {
                continue;
            }

            $successor = $value->polyslugSupersededBy();

            if ($successor instanceof Sluggable) {
                return Container::getInstance()->make(Redirector::class)->to(
                    $this->successorUrl($route, $name, $successor, $locale),
                    $this->configuredStatus('polyslug.gone.redirect_status', 301),
                );
            }
        }

        return null;
    }

    private function successorUrl(Route $route, string $supersededParam, Sluggable $successor, string $locale): string
    {
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
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
