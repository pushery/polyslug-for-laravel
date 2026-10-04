<?php

declare(strict_types=1);

namespace Polyslug\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Polyslug\Contracts\Sluggable;

/**
 * Lets a parent model bind a Polyslug child under scoped route bindings.
 *
 * `Route::scopeBindings()`, `->scoped()` and a parameter after a parent (`/owners/{owner}/pages/{page}`)
 * resolve the child through the parent's relation, narrowed by the route value. Laravel narrows
 * it by comparing the whole value with the child's key column, and a Polyslug route key is a slug
 * and a token, so the comparison never matches and the child answers 404. With this trait on the
 * PARENT model, the value is resolved the way the child's own binding resolves it, its resolution
 * gate included, and the relation is narrowed to that record: a child of another parent still
 * answers 404. A binding that names a field (`{page:uuid}`) is resolved and narrowed the same way,
 * by that field. A child that is not a Polyslug model keeps Laravel's own resolution.
 *
 * It belongs on the parent because the child cannot carry it: Laravel's HasUuids and HasUlids
 * define the child-side method already, and two traits that define one method are a fatal error.
 *
 * @mixin Model
 */
trait ResolvesSluggableChildren
{
    public function resolveChildRouteBinding(mixed $childType, mixed $value, mixed $field): ?Model
    {
        return $this->polyslugResolveChild($childType, $value, $field, withTrashed: false);
    }

    /**
     * The same binding on a route declared with `->withTrashed()`, which Laravel resolves
     * through this method instead: deleted children of this parent are admitted.
     */
    public function resolveSoftDeletableChildRouteBinding(mixed $childType, mixed $value, mixed $field): ?Model
    {
        return $this->polyslugResolveChild($childType, $value, $field, withTrashed: true);
    }

    private function polyslugResolveChild(string $childType, mixed $value, ?string $field, bool $withTrashed): ?Model
    {
        $relationship = $this->{$this->childRouteBindingRelationshipName($childType)}();
        $related = $relationship instanceof Relation ? $relationship->getRelated() : null;

        if (! $related instanceof Sluggable) {
            if ($withTrashed) {
                return parent::resolveSoftDeletableChildRouteBinding($childType, $value, $field);
            }

            return parent::resolveChildRouteBinding($childType, $value, $field);
        }

        $child = $withTrashed ? $related->resolveSoftDeletableRouteBinding($value, $field) : $related->resolveRouteBinding($value, $field);

        if (! $child instanceof Model) {
            return null;
        }

        // Narrowed to the resolved key on the parent's own relation, so a record of another
        // parent is not found here even though it resolved on its own.
        $query = $relationship->whereKey($child->getKey());

        if ($withTrashed) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->first();
    }
}
