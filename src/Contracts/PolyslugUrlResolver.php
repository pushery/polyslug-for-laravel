<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

/**
 * Turns a sluggable model + locale into its absolute canonical URL. The package cannot
 * know the host's URL structure, so bind an implementation in the container; the sitemap
 * generator and the /go short-link resolver both use it. Typically wraps route()/url()
 * with the model's polyslugRouteKeyForLocale($locale).
 */
interface PolyslugUrlResolver
{
    public function url(Sluggable $model, string $locale): string;
}
