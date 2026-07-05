<?php

declare(strict_types=1);

namespace Polyslug\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;
use Polyslug\Contracts\Sluggable;
use Polyslug\Events\SlugRedirected;
use Symfony\Component\HttpFoundation\Response;

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

        if ($this->isSafe($request) && $route instanceof Route) {
            $locale = $this->requestLocale($route);

            // Gone / superseded content is terminal — it takes precedence over same-model self-heal.
            $terminal = $this->terminalRedirect($route, $locale);

            if ($terminal instanceof Response) {
                return $terminal;
            }

            if ($this->hasStaleSlug($route, $locale)) {
                $url = $this->canonicalUrl($request, $route, $locale);
                $status = $this->status();
                $this->recordRedirect($route, $locale, $url, $status);

                return redirect()->to($url, $status);
            }
        }

        return $next($request);
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

        $url = app(UrlGenerator::class)->toRoute($route, $parameters, true);
        $query = $request->getQueryString();

        return $query === null ? $url : $url.'?'.$query;
    }

    private function requestLocale(Route $route): string
    {
        $config = app(ConfigRepository::class);

        if ($config->get('polyslug.locale.source') === 'route') {
            $param = $config->get('polyslug.locale.route_param', 'locale');
            $value = $route->parameter(is_string($param) ? $param : 'locale');

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return app()->getLocale();
    }

    private function status(): int
    {
        $status = app(ConfigRepository::class)->get('polyslug.redirect.status', 301);

        return is_int($status) ? $status : 301;
    }

    private function terminalRedirect(Route $route, string $locale): ?Response
    {
        foreach ($route->parameters() as $name => $value) {
            if (! $value instanceof Sluggable) {
                continue;
            }

            if ($value->polyslugIsGone()) {
                abort($this->configuredStatus('polyslug.gone.status', 410));
            }

            $successor = $value->polyslugSupersededBy();

            if ($successor instanceof Sluggable) {
                return redirect()->to(
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

        return app(UrlGenerator::class)->toRoute($route, $parameters, true);
    }

    private function configuredStatus(string $key, int $default): int
    {
        $status = app(ConfigRepository::class)->get($key, $default);

        return is_int($status) ? $status : $default;
    }

    private function recordRedirect(Route $route, string $locale, string $url, int $status): void
    {
        if (app(ConfigRepository::class)->get('polyslug.analytics.enabled') !== true) {
            return;
        }

        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof Sluggable
                && is_string($requested = $route->originalParameter($name))
                && $requested !== $value->polyslugRouteKeyForLocale($locale)) {
                event(new SlugRedirected($value, $requested, $url, $locale, $status));

                return;
            }
        }
    }
}
