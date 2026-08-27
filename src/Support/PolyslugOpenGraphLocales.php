<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Laravel\Head\Rendering\ResolvedHead;
use Laravel\Head\Rendering\TagRenderer;
use Laravel\Head\Tags\GroupedTagBuilder;
use Laravel\Head\Tags\TagBuilder;

/**
 * The Open Graph alternate-locale set, as ONE tag per locale.
 *
 * og:locale:alternate is the single Open Graph property that is legitimately
 * repeated: a page in four languages carries three of them. laravel/head's generic
 * meta() cannot express that, and not by oversight — MetaTags keys every tag by
 * attribute|key|media, so a second call with the same key REPLACES the first. Four
 * locales therefore rendered exactly one alternate, the alphabetically last, and the
 * loop that wrote them looked correct on every reading.
 *
 * A repeated key needs a builder of its own, which is why laravel/head has
 * TagRegistry::extend(). This is that builder: it holds the set, and emits the set.
 *
 * ── WHY THIS FILE MAY NAME Laravel\Head AT ALL ───────────────────────────────────
 * It extends a laravel/head class, so it fatals if it is ever loaded without the
 * package installed. It cannot be: nothing references it by anything but ::class
 * (which does not autoload) outside PolyslugHead, and PolyslugHead itself is only
 * ever reached through the macro the provider registers behind
 * class_exists(HeadManager::class). LaravelHeadContractTest holds that file set at
 * three and names each one, so a fourth is a deliberate act rather than a drift.
 */
final class PolyslugOpenGraphLocales extends GroupedTagBuilder
{
    /**
     * @param  array<int, string>  $locales  Open Graph locale strings, already in language_TERRITORY form.
     */
    public function __construct(private readonly array $locales = [])
    {
        //
    }

    public static function key(): string
    {
        return 'polyslugOpenGraphLocales';
    }

    /**
     * No route attribute. The set is derived from a model at render time, and a route
     * attribute is fixed when the route is DEFINED — there is no model there to ask.
     * Returning an empty list also keeps TagRegistry::extend() from ever rejecting this
     * builder over a key another builder already claims.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return [];
    }

    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        // Union, not replace — the same stance AlternateLinks takes, and for the same
        // reason: a consumer who declared an alternate locale by hand must not lose it
        // because a Polyslug model later described its own set.
        return new self(array_values(array_unique([...$base->locales, ...$this->locales])));
    }

    public function isEmpty(): bool
    {
        return $this->locales === [];
    }

    /**
     * @return array<int, string>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->locales;
    }

    /**
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(
            // The fourth argument is the data-inertia ownership key, and it must differ
            // per tag: Inertia reconciles the head BY that key, so one shared key would
            // reintroduce on the client exactly the collapse this builder exists to fix.
            fn (string $locale): string => $tags->meta(
                'property',
                'og:locale:alternate',
                $locale,
                'og:locale:alternate:'.$locale,
            ),
            $this->locales,
        );
    }
}
