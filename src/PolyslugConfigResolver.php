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
 * per class. Lives outside HasPolyslug so the ConfiguresPolyslug dispatch is analyzed
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
     * The attribute of the nearest class in the parent chain that carries one.
     *
     * PHP does not inherit attributes, so a model extending a configured one has none of its
     * own, while it is the same kind of record with the same slugs. A subclass that declares the
     * attribute itself replaces the inherited one.
     *
     * @param  class-string  $class
     */
    private static function fromAttribute(string $class): PolyslugConfig
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(PolyslugAttribute::class);

            if ($attributes !== []) {
                return PolyslugConfig::fromAttribute($attributes[0]->newInstance());
            }
        }

        throw new MissingPolyslugConfig($class);
    }
}
