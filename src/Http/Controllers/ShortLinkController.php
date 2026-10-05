<?php

declare(strict_types=1);

namespace Polyslug\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;
use Polyslug\Models\PolyslugShortLink;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a /go/{token} short link to its model and 301s to the model's CURRENT
 * canonical URL (built by the bound PolyslugUrlResolver), with the request's query string
 * carried over. Route it yourself:
 *
 *     Route::get('/go/{token}', ShortLinkController::class);
 */
final class ShortLinkController
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $link = PolyslugShortLink::model()::query()->where('token', $token)->first();

        // No such link, or no way to build the target URL → a clean 404.
        if ($link === null || ! Container::getInstance()->bound(PolyslugUrlResolver::class)) {
            throw new NotFoundHttpException;
        }

        $class = Relation::getMorphedModel($link->sluggable_type) ?? $link->sluggable_type;

        if (! is_a($class, Model::class, true)) {
            throw new NotFoundHttpException;
        }

        $prototype = new $class;

        if (! $prototype instanceof Sluggable) {
            throw new NotFoundHttpException;
        }

        // Resolve THROUGH the visibility gate — a short link must not reach a row the
        // request may not see (no cross-tenant / draft existence oracle on /go).
        $model = $prototype->polyslugResolveByKey($link->sluggable_id);

        if (! $model instanceof Sluggable) {
            throw new NotFoundHttpException;
        }

        $url = Container::getInstance()->make(PolyslugUrlResolver::class)->url($model, $link->locale);

        return Container::getInstance()->make(Redirector::class)->to($this->withRequestQuery($url, $request->getQueryString()), 301);
    }

    /**
     * The resolver's URL with the request's query string carried over, the way the redirects of
     * EnsureCanonicalSlug carry it.
     *
     * The resolver builds the canonical address, so its URL is kept exactly as it came, query
     * and fragment included. The request adds the parameters whose names the resolver does not
     * set, in front of a fragment. Names are compared as they are written, so `utm.source` and
     * `utm_source` stay two parameters.
     */
    private function withRequestQuery(string $url, ?string $query): string
    {
        if ($query === null || $query === '') {
            return $url;
        }

        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $base = $hash === false ? $url : substr($url, 0, $hash);
        $mark = strpos($base, '?');

        if ($mark === false) {
            return $base.'?'.$query.$fragment;
        }

        $own = substr($base, $mark + 1);
        $ownNames = array_map(self::parameterName(...), $this->parameters($own));
        $added = array_filter(
            $this->parameters($query),
            static fn (string $pair): bool => ! in_array(self::parameterName($pair), $ownNames, true),
        );

        if ($added === []) {
            return $base.$fragment;
        }

        return $base.($own === '' ? '' : '&').implode('&', $added).$fragment;
    }

    /**
     * The `name=value` pairs of a query string, empty ones left out.
     *
     * @return list<string>
     */
    private function parameters(string $query): array
    {
        return array_values(array_filter(explode('&', $query), static fn (string $pair): bool => $pair !== ''));
    }

    private static function parameterName(string $pair): string
    {
        return rawurldecode(explode('=', $pair, 2)[0]);
    }
}
