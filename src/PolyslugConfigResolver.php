<?php

declare(strict_types=1);

namespace Polyslug;

use Illuminate\Database\Eloquent\Model;
use Polyslug\Attributes\Polyslug as PolyslugAttribute;
use Polyslug\Contracts\ConfiguresPolyslug;
use Polyslug\Exceptions\MisconfiguredPolyslug;
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
            return self::forModel($model->polyslug());
        }

        return self::$cache[$model::class] ??= self::forModel(self::fromAttribute($model::class));
    }

    /**
     * The configuration, once it is known to belong to a model.
     *
     * A model's slug is built from its source columns, so a model must name them; the one
     * exception is a slugless model, whose URL is the token alone. Checked here rather than in
     * the PolyslugConfig constructor, because a configuration handed to the slug generator alone
     * brings its source in the request and has no column to name. Every model's configuration
     * passes through here, the attribute and a polyslug() override alike, so omitting the source
     * on a model is still refused before its first slug, instead of giving every record an empty
     * one.
     */
    private static function forModel(PolyslugConfig $config): PolyslugConfig
    {
        if (! $config->slugless && $config->source === []) {
            throw MisconfiguredPolyslug::sourceIsRequired();
        }

        return $config;
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
