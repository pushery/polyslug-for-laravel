<?php

declare(strict_types=1);

namespace Polyslug\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A `morphMany` whose eager constraint is BOUND rather than inlined, a thousand keys a query.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringKeyedMorphMany extends MorphMany
{
    /**
     * How many parent keys one eager query binds.
     *
     * PostgreSQL and MySQL refuse a statement with more than 65,535 parameters, and the bound
     * constraint below costs one per parent.
     */
    private const int EAGER_SLICE = 1_000;

    /**
     * The parent keys of an eager load larger than one slice, read a slice at a time.
     *
     * @var list<list<mixed>>|null
     */
    private ?array $eagerKeySlices = null;

    private string $eagerKeyColumn = '';

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

    /**
     * Constrain to the parent keys, or keep them for a query per slice when there are more.
     *
     * Every parent is a bound parameter, so eager-loading `slugs` over more than 65,535 records
     * was refused on PostgreSQL and MySQL. Up to a slice the constraint is the one it always was;
     * beyond it, getEager() asks once per slice.
     *
     * @param  array<array-key, mixed>  $modelKeys
     * @param  Builder<TRelatedModel>|null  $query
     */
    protected function whereInEager(string $whereIn, string $key, array $modelKeys, ?Builder $query = null): void
    {
        if (count($modelKeys) <= self::EAGER_SLICE) {
            parent::whereInEager($whereIn, $key, $modelKeys, $query);

            return;
        }

        $this->eagerKeySlices = array_chunk(array_values($modelKeys), self::EAGER_SLICE);
        $this->eagerKeyColumn = $key;
    }

    /**
     * The related rows of every parent, a slice of parent keys a query when there are more than one
     * slice of them. Each query is the relation's own, with its type and any constraint the caller
     * added, so the rows are the ones a single query would have read.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function getEager(): Collection
    {
        if ($this->eagerKeySlices === null) {
            return parent::getEager();
        }

        $results = [];

        foreach ($this->eagerKeySlices as $slice) {
            foreach ((clone $this->query)->whereIn($this->eagerKeyColumn, $slice)->get() as $related) {
                $results[] = $related;
            }
        }

        return $this->related->newCollection($results);
    }
}
