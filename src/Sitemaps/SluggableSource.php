<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\SitemapSource;
use Polyslug\Contracts\Sluggable;
use Polyslug\Support\SuccessorChain;
use Throwable;

/**
 * The records of sluggable models, addressed by a URL resolver: the source of `polyslug:sitemap`,
 * and of any sitemap that lists models.
 *
 * A record's key is the position of its type among the types given and its primary key, an integer
 * key written as twenty digits so that its byte order is its numeric order. key() gives it for a
 * model, which is what Sitemap::rebuild() takes. A string key is read in the order the database
 * sorts the key column, which is its byte order for a ULID or a UUID; a column that sorts otherwise
 * makes every rebuild of the source cut it anew, which stays correct and costs a whole read.
 */
final class SluggableSource implements SitemapSource
{
    /**
     * How many rows are read, and have their slugs and tokens loaded, in one round.
     *
     * Small enough that one round of models and slug rows is a modest amount of memory, large enough
     * that the queries per round stop mattering against the rows they serve.
     */
    private const int ROWS_PER_ROUND = 500;

    /** @var list<class-string<Model>> */
    private readonly array $types;

    /**
     * Records the resolver could not address, by class.
     *
     * @var array<class-string, int>
     */
    private array $unaddressed = [];

    /**
     * @param  string|array<mixed>  $types  the models listed, in their order; an entry that is not
     *                                      an Eloquent model implementing Sluggable is left out
     */
    public function __construct(
        private readonly string $name,
        string|array $types,
        private readonly PolyslugUrlResolver $resolver,
    ) {
        $listed = [];

        foreach (is_array($types) ? $types : [$types] as $type) {
            if (is_string($type) && is_a($type, Model::class, true) && is_a($type, Sluggable::class, true)) {
                $listed[] = $type;
            }
        }

        $this->types = $listed;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The key a model carries in this source, as Sitemap::rebuild() takes it; null for a model of a
     * type the source does not list.
     */
    public function key(Model $model): ?string
    {
        $position = array_search($model::class, $this->types, true);

        return $position === false ? null : $this->keyOf($position, $model);
    }

    public function records(string $from = '', ?string $until = null): iterable
    {
        $first = $from === '' ? 0 : (int) substr($from, 0, 4);
        $last = $until === null ? count($this->types) : (int) substr($until, 0, 4);

        foreach ($this->types as $position => $class) {
            if ($position < $first || $position > $last) {
                continue;
            }

            $lower = $from !== '' && $position === $first ? substr($from, 5) : null;
            $upper = $until !== null && $position === $last ? substr($until, 5) : null;

            foreach ($this->rows($class, $lower, $upper) as $model) {
                if ($model instanceof Sluggable) {
                    $alternates = $this->alternatesOf($model);

                    if ($alternates !== []) {
                        yield new SitemapRecord($this->keyOf($position, $model), $alternates, $this->lastModified($model));
                    }
                }
            }
        }
    }

    /**
     * The records the resolver threw on instead of returning a URL, counted by class since the
     * source was made.
     *
     * A sitemap missing one type is worth more than no sitemap, so a throw costs its record and not
     * the run. But a skip nobody is told about is how a type quietly stops being announced, so the
     * count is there to be reported.
     *
     * @return array<class-string, int>
     */
    public function unaddressed(): array
    {
        return $this->unaddressed;
    }

    /**
     * The rows of one type within the keys given, through the model's own resolution gate.
     *
     * Route binding and every resolution path go through polyslugResolveQuery(), so a row the gate
     * hides answers 404 at its address, and listing it would submit an address that does not exist.
     * A crawler is an anonymous requester and so is a sitemap: a gate that reads a session or a tenant
     * sees here what a crawler would see. method_exists() rather than a contract method, because the
     * gate lives on HasPolyslug and a model implementing Sluggable by hand keeps its whole table.
     *
     * Read in rounds, each with its slug rows eager-loaded and its tokens fetched by
     * polyslugPreload() in one query, so a run costs a few queries per round rather than several per
     * record. A model that computes its slug (`stored: false`) holds no slug rows to load.
     *
     * @param  class-string<Model>  $class
     * @return iterable<int, Model>
     */
    private function rows(string $class, ?string $from, ?string $until): iterable
    {
        $model = new $class;
        $gated = method_exists($model, 'polyslugResolveQuery') ? $model->polyslugResolveQuery($model->newQuery()) : null;
        $query = $gated instanceof Builder ? $gated : $model->newQuery();
        $integer = in_array($model->getKeyType(), ['int', 'integer'], true);

        if (method_exists($model, 'polyslugStoresSlugs') && $model->polyslugStoresSlugs() === true) {
            $query->with('slugs');
        }

        if ($from !== null) {
            $query->where($model->getQualifiedKeyName(), '>=', $integer ? (int) $from : $from);
        }

        if ($until !== null) {
            $query->where($model->getQualifiedKeyName(), '<', $integer ? (int) $until : $until);
        }

        // reorder() because a resolution gate may sort its query, and lazyById() keeps a foreign
        // order in front of the key it pages by: each round would be sorted by the gate's column
        // while the cursor moves by the key, and rows would be skipped or listed twice.
        foreach ($query->reorder()->lazyById(self::ROWS_PER_ROUND)->chunk(self::ROWS_PER_ROUND) as $round) {
            if (method_exists($model, 'polyslugPreload')) {
                $model->polyslugPreload($round);
            }

            yield from $round->values();
        }
    }

    /**
     * Every address the record is served at, by locale and `x-default`, as the page head announces
     * them: none for a record that answers 410 or 301, and none for one the resolver cannot address.
     *
     * The precedence is the canonical middleware's, in its order. A gone record answers 410, and a
     * superseded one whose successor the gate lets through answers 301; a successor the requester may
     * not see, or a chain that comes back to the record, leaves the record served and so listed. The
     * addresses come from hreflangLinks(), the method that also feeds the page head, so the two
     * cannot disagree about which addresses a record has.
     *
     * A resolver says it cannot address a record through an optional canAddress(), silently, since
     * that is a decision somebody made. One that throws instead costs that record and is counted. A
     * database error says nothing about whether a record can be addressed, wherever it is raised, so
     * it ends the read.
     *
     * @return array<string, string>
     */
    private function alternatesOf(Sluggable $model): array
    {
        if ($model->polyslugIsGone() || SuccessorChain::lastVisible($model) instanceof Sluggable) {
            return [];
        }

        $resolver = $this->resolver;

        if (method_exists($resolver, 'canAddress') && $resolver->canAddress($model) !== true) {
            return [];
        }

        $refused = false;

        $urls = $model->hreflangLinks(function (string $locale, string $routeKey) use ($model, $resolver, &$refused): string {
            try {
                return $resolver->url($model, $locale);
            } catch (Throwable $exception) {
                if ($exception instanceof QueryException) {
                    throw $exception;
                }

                $refused = true;

                return '';
            }
        });

        if ($refused) {
            $this->unaddressed[$model::class] = ($this->unaddressed[$model::class] ?? 0) + 1;

            return [];
        }

        return $urls;
    }

    /**
     * When the record last changed, for a model that says so through polyslugLastModified(), which
     * lives on HasPolyslug rather than on the contract.
     */
    private function lastModified(Sluggable $model): ?DateTimeInterface
    {
        $modified = method_exists($model, 'polyslugLastModified') ? $model->polyslugLastModified() : null;

        return $modified instanceof DateTimeInterface ? $modified : null;
    }

    private function keyOf(int $position, Model $model): string
    {
        $key = $model->getKey();
        $inner = in_array($model->getKeyType(), ['int', 'integer'], true) && is_numeric($key)
            ? sprintf('%020d', (int) $key)
            : (is_scalar($key) ? (string) $key : '');

        return sprintf('%04d', $position).':'.$inner;
    }
}
