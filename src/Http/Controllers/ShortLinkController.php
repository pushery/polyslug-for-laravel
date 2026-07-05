<?php

declare(strict_types=1);

namespace Polyslug\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\Sluggable;
use Polyslug\Models\PolyslugShortLink;

/**
 * Resolves a /go/{token} short link to its model and 301s to the model's CURRENT
 * canonical URL (built by the bound PolyslugUrlResolver). Route it yourself:
 *
 *     Route::get('/go/{token}', ShortLinkController::class);
 */
final class ShortLinkController
{
    public function __invoke(string $token): RedirectResponse
    {
        $link = PolyslugShortLink::query()->where('token', $token)->first();

        // No such link, or no way to build the target URL → a clean 404.
        if ($link === null || ! app()->bound(PolyslugUrlResolver::class)) {
            abort(404);
        }

        $class = Relation::getMorphedModel($link->sluggable_type) ?? $link->sluggable_type;

        if (! is_a($class, Model::class, true)) {
            abort(404);
        }

        $prototype = new $class;

        if (! $prototype instanceof Sluggable) {
            abort(404);
        }

        // Resolve THROUGH the visibility gate — a short link must not reach a row the
        // request may not see (no cross-tenant / draft existence oracle on /go).
        $model = $prototype->polyslugResolveByKey($link->sluggable_id);

        if (! $model instanceof Sluggable) {
            abort(404);
        }

        return redirect()->to(app(PolyslugUrlResolver::class)->url($model, $link->locale), 301);
    }
}
