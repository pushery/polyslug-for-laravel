<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Support\HtmlString;
use Polyslug\Contracts\Sluggable;

/**
 * Runtime target of the @polyslugHreflang Blade directive. Kept as a plain method so
 * the compiled directive is a simple, analyzable call rather than inline logic.
 */
final class PolyslugBlade
{
    /**
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public static function hreflang(Sluggable $model, callable $urlUsing): HtmlString
    {
        return $model->hreflangTags($urlUsing);
    }
}
