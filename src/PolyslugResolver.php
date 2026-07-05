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

    public function resolve(string $type, string $value): ?Model
    {
        $types = $this->config->get('polyslug.types', []);
        $class = is_array($types) && isset($types[$type]) ? $types[$type] : null;

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, Sluggable::class, true)) {
            return null;
        }

        return (new $class)->resolveRouteBinding($value);
    }
}
