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
     * The arguments of hreflangTags(), the x-default locale included, so the directive takes what
     * the method it stands for takes.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public static function hreflang(Sluggable $model, callable $urlUsing, ?string $xDefault = null): HtmlString
    {
        return $model->hreflangTags($urlUsing, $xDefault);
    }
}
