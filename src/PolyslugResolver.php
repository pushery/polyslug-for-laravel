<?php

declare(strict_types=1);

namespace Polyslug;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;

/**
 * Resolves a `{type}/{slug_id}` pair to a model using the polyslug.types registry —
 * one route can serve every registered content type. An unknown type or an
 * unresolvable value yields null (the caller turns that into a 404).
 */
final readonly class PolyslugResolver
{
    public function __construct(private ConfigRepository $config) {}

    /**
     * $field and $withTrashed carry what a route declares for its parameter, a binding field
     * (`{polyslug:uuid}`) and `->withTrashed()`, and they are applied the way implicit route
     * binding applies them: deleted records are admitted only for a model that can be
     * soft-deleted.
     */
    public function resolve(string $type, string $value, ?string $field = null, bool $withTrashed = false): ?Model
    {
        $types = $this->config->get('polyslug.types', []);
        $class = is_array($types) && isset($types[$type]) ? $types[$type] : null;

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, Sluggable::class, true)) {
            return null;
        }

        $model = new $class;

        return $withTrashed && $model::isSoftDeletable()
            ? $model->resolveSoftDeletableRouteBinding($value, $field)
            : $model->resolveRouteBinding($value, $field);
    }
}
