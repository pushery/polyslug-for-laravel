<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Models\PolyslugShortLink;
use Polyslug\Models\PolyslugSlug;
use Polyslug\Support\ForgottenRecords;
use Polyslug\Support\TokenStore;

/**
 * Forgets what Polyslug holds for records that no longer exist.
 *
 * `polyslugForget()` covers the deletes an application knows about. A foreign-key cascade, a delete
 * from before that call existed, or a new way of deleting that leaves it out keeps the slug rows,
 * with text from the records' titles, and their short links and identity tokens. This finds them:
 * for every model type the stores hold rows for, the keys whose record is gone, asked without global
 * scopes, so a record in the trash counts as present and keeps everything. Those keys go to
 * {@see ForgottenRecords::forget()}, which deletes the slug rows and keeps every token taken.
 *
 * The types are read from the short links, the identity tokens and the slug rows on the slug
 * model's own connection. A model whose slugs live on a connection of its own and that holds no short
 * link or token is named with `--type`.
 */
final class ForgetOrphansCommand extends Command implements Isolatable
{
    /** @var string */
    protected $signature = 'polyslug:forget-orphans
        {--type=* : A model type to look at, as stored or as its class. Every type the stores hold rows for by default.}
        {--dry-run : Count the records that are gone, per type, and change nothing.}';

    /** @var string */
    protected $description = 'Forget the slugs, short links and identity tokens of records that no longer exist.';

    /** How many keys one page reads and one existence query binds. */
    private const int PAGE = 1_000;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $types = $this->types();

        if ($types === []) {
            $this->info('The stores hold no rows of a model type. Nothing to forget.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($types as $type) {
            $model = $this->modelOf($type);
            $relation = $model instanceof Model && method_exists($model, 'slugs') ? $model->slugs() : null;
            $slugs = $relation instanceof Relation ? $relation->getRelated() : null;

            if (! $model instanceof Model || ! $slugs instanceof PolyslugSlug) {
                $this->warn(sprintf('[%s] names no model that uses HasPolyslug under this type. Its rows are left as they are.', $type));

                continue;
            }

            $gone = $this->forgetGone($type, $model, $slugs, $dryRun);
            $total += $gone;

            $this->line(sprintf(
                $dryRun ? '[%s] %d %s gone, to be forgotten.' : '[%s] %d %s gone, forgotten.',
                $type,
                $gone,
                $gone === 1 ? 'record' : 'records',
            ));
        }

        $this->info($dryRun
            ? sprintf('Dry run: %d %s gone, nothing was changed.', $total, $total === 1 ? 'record is' : 'records are')
            : sprintf('%d %s forgotten.', $total, $total === 1 ? 'record' : 'records'));

        return self::SUCCESS;
    }

    /**
     * The types to look at: the ones given, or every model type the stores hold rows for.
     *
     * @return list<string>
     */
    private function types(): array
    {
        $given = [];

        foreach ((array) $this->option('type') as $type) {
            if (is_string($type) && $type !== '') {
                $given[] = $this->storedType($type);
            }
        }

        $types = $given !== []
            ? $given
            : [
                ...$this->typesIn(PolyslugSlug::resolve()->newModelQuery()->toBase(), 'sluggable_type'),
                ...$this->typesIn(PolyslugShortLink::resolve()->newModelQuery()->toBase(), 'sluggable_type'),
                ...$this->typesIn(DB::table('polyslug_tokens'), 'key_type'),
            ];

        $types = array_values(array_unique(array_diff($types, [TokenStore::UNTYPED, ForgottenRecords::TYPE])));
        sort($types);

        return $types;
    }

    /** @return list<string> */
    private function typesIn(Builder $store, string $column): array
    {
        $types = [];

        foreach ($store->distinct()->pluck($column)->all() as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** A type as the stores hold it: the morph type of a model class, or the value as given. */
    private function storedType(string $given): string
    {
        return class_exists($given) && is_a($given, Model::class, true) ? new $given()->getMorphClass() : $given;
    }

    /**
     * The model a type belongs to, or null where no model that uses HasPolyslug has it.
     *
     * A row written under another type than its model has today, under the class name before a
     * morph map named it for instance, names no model this can ask, and stays.
     */
    private function modelOf(string $type): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true) || ! in_array(HasPolyslug::class, class_uses_recursive($class), true)) {
            return null;
        }

        $model = new $class;

        return $model->getMorphClass() === $type ? $model : null;
    }

    /**
     * Forget the keys of a type whose record is gone, in every store, and count them.
     *
     * Each store is read a page of keys at a time, from the last key of the page before, so the
     * rows a page forgets never move the next one.
     */
    private function forgetGone(string $type, Model $model, PolyslugSlug $slugs, bool $dryRun): int
    {
        /** @var list<array{0: Closure(): Builder, 1: string, 2: string}> $stores */
        $stores = [
            [static fn (): Builder => $slugs->newModelQuery()->toBase(), 'sluggable_type', 'sluggable_id'],
            [static fn (): Builder => PolyslugShortLink::resolve()->newModelQuery()->toBase(), 'sluggable_type', 'sluggable_id'],
            [static fn (): Builder => DB::table('polyslug_tokens'), 'key_type', 'key_value'],
        ];

        $gone = [];

        foreach ($stores as [$store, $typeColumn, $keyColumn]) {
            $after = null;

            do {
                $query = $store()->where($typeColumn, $type);

                if ($after !== null) {
                    $query->where($keyColumn, '>', $after);
                }

                $page = array_values(array_map(
                    static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
                    $query->distinct()->orderBy($keyColumn)->limit(self::PAGE)->pluck($keyColumn)->all(),
                ));

                $missing = $this->gone($model, $page);

                if (! $dryRun && $missing !== []) {
                    ForgottenRecords::forget($type, $missing, $slugs);
                }

                foreach ($missing as $key) {
                    $gone[$key] = true;
                }

                $after = $page === [] ? null : $page[count($page) - 1];
            } while (count($page) === self::PAGE);
        }

        return count($gone);
    }

    /**
     * The keys of a page whose record is gone, asked without global scopes, so a record in the
     * trash counts as present.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function gone(Model $model, array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));

        if ($keys === []) {
            return [];
        }

        $present = array_map(
            static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
            $model->newQueryWithoutScopes()->whereKey($keys)->pluck($model->getKeyName())->all(),
        );

        return array_values(array_diff($keys, $present));
    }
}
