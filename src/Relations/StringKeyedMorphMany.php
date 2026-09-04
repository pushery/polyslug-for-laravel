<?php

declare(strict_types=1);

namespace Polyslug\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A `morphMany` whose eager constraint is BOUND rather than inlined.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringKeyedMorphMany extends MorphMany
{
    /**
     * Force the bound `whereIn` over Eloquent's `whereIntegerInRaw`.
     *
     * `Relation::whereInMethod()` picks `whereIntegerInRaw` whenever the local key is the
     * parent's own integer primary key — which is every ordinary Eloquent model. That path
     * casts the keys to int and writes them INTO the SQL text, unquoted:
     *
     *     where "polyslug_slugs"."sluggable_id" in (1, 2)
     *
     * A polymorphic key column is a varchar, because it has to hold UUIDs and ULIDs as well
     * as integers. PostgreSQL types a bare `1` in the statement text as `integer`, finds no
     * `varchar = integer` operator, and refuses the whole statement.
     *
     * Binding instead of inlining is what fixes it, and the reason is specific: a bound
     * parameter is sent with no declared type, so PostgreSQL infers it from the column it is
     * compared against. That is also why the LAZY path never broke. It has always issued a
     * bound `where "sluggable_id" = ?`.
     *
     * @param  string  $key
     */
    protected function whereInMethod(Model $model, $key): string
    {
        return 'whereIn';
    }
}
