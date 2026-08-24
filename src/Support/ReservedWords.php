<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use Polyslug\PolyslugConfig;

/**
 * The reserved-word list a model INHERITS, before the model has had its say.
 *
 * Extracted from DefaultSlugGenerator so the trait and the generator resolve the same
 * list from the same code. They both need it and they must not disagree: the trait
 * offers the list to the model for filtering, the generator falls back to computing it
 * when the caller handed none over, and two copies of this arithmetic would be two
 * things that drift.
 */
final class ReservedWords
{
    /**
     * The per-model reserved words, the app-wide polyslug.reserved.global list, and — when
     * polyslug.reserved.from_routes is on — the static first segment of every registered
     * route.
     *
     * @return list<string>
     */
    public static function inherited(PolyslugConfig $config): array
    {
        $global = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.reserved.global', []);

        $reserved = array_merge(
            $config->reserved,
            is_array($global) ? array_values(array_filter($global, is_string(...))) : [],
        );

        if (Container::getInstance()->make(ConfigRepository::class)->get('polyslug.reserved.from_routes') === true) {
            return array_merge($reserved, self::registeredRoutePaths());
        }

        return $reserved;
    }

    /**
     * The static first segment of every registered route, so a generated slug can never
     * shadow a real route (e.g. /login, /admin) when polyslug.reserved.from_routes is on.
     *
     * @return list<string>
     */
    private static function registeredRoutePaths(): array
    {
        $paths = [];

        foreach (Container::getInstance()->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $first = explode('/', $route->uri())[0];

            if ($first !== '' && ! str_contains($first, '{')) {
                $paths[] = $first;
            }
        }

        return array_values(array_unique($paths));
    }
}
