<?php

declare(strict_types=1);

namespace Polyslug;

use Illuminate\Database\Eloquent\Model;
use Polyslug\Attributes\Polyslug as PolyslugAttribute;
use Polyslug\Contracts\ConfiguresPolyslug;
use Polyslug\Exceptions\MissingPolyslugConfig;
use ReflectionClass;

/**
 * Resolves a model's PolyslugConfig. A model implementing ConfiguresPolyslug computes
 * its config at runtime (resolved fresh, never cached, so it can vary per tenant);
 * otherwise the static #[Polyslug] attribute is read once via reflection and cached
 * per class. Lives outside the trait so the ConfiguresPolyslug dispatch is analyzed
 * generically rather than "in context of" every using model.
 */
final class PolyslugConfigResolver
{
    /** @var array<class-string, PolyslugConfig> */
    private static array $cache = [];

    public static function resolve(Model $model): PolyslugConfig
    {
        if ($model instanceof ConfiguresPolyslug) {
            return $model->polyslug();
        }

        return self::$cache[$model::class] ??= self::fromAttribute($model::class);
    }

    /**
     * @param  class-string  $class
     */
    private static function fromAttribute(string $class): PolyslugConfig
    {
        $attributes = new ReflectionClass($class)->getAttributes(PolyslugAttribute::class);

        if ($attributes === []) {
            throw new MissingPolyslugConfig($class);
        }

        return PolyslugConfig::fromAttribute($attributes[0]->newInstance());
    }
}
