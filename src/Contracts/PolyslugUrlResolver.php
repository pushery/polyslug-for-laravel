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

    /*
     * OPTIONAL, AND DELIBERATELY NOT DECLARED HERE:
     *
     *     public function canAddress(Sluggable $model): bool
     *
     * `url()` returns a string, so an implementation that cannot address a record has no way
     * to say so except by throwing -- and `polyslug:sitemap` walks every configured type, so
     * one throw used to end the whole run: no document at all, rather than one entry fewer.
     * Reported from a consuming application whose configured types included a model nothing
     * routes.
     *
     * Declaring `canAddress()` on this interface would break every existing implementation, so
     * the command reaches it through method_exists() instead -- the same additive shape
     * HasPolyslug uses for polyslugRobotsDirective() and polyslugLastModified(). Implement it
     * to say "this record has no public address"; leave it out and nothing changes.
     *
     * A throw is still survived, because a resolver can fail for reasons nobody declared. The
     * difference is what the operator is told: a declared refusal is silent, a throw is
     * counted and reported. Only one of the two is a decision.
     */
}
