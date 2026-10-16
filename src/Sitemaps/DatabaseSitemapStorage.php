<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Polyslug\Contracts\SitemapStorage;

/**
 * A sitemap in a table of the application's, a row for each part, so every node of a deployment
 * serves the same sitemap without a shared disk.
 *
 * The table holds the columns `source`, `number`, `starts_at`, `urls`, `lastmod` and `body`, and
 * whatever sets one sitemap's rows apart from another's: the scope given here, a tenant's key for
 * one, is matched on every read and written with every new row. A write is a transaction on the
 * table's connection, and a write reads the parts it plans from with `FOR UPDATE`, so two writes of
 * one sitemap that already has rows wait for each other instead of interleaving.
 *
 * Each part is served as `<source>-<number>.xml`.
 */
final class DatabaseSitemapStorage implements SitemapStorage
{
    private bool $writing = false;

    /**
     * @param  array<string, bool|float|int|string>  $scope  the columns and values that set this
     *                                                       sitemap's rows apart from every other
     *                                                       sitemap's in the table
     */
    public function __construct(
        private readonly string $table,
        private readonly array $scope = [],
        private readonly ?string $connection = null,
    ) {}

    public function parts(): array
    {
        $query = $this->rows()->select(['source', 'number', 'starts_at', 'urls', 'lastmod'])->orderBy('source')->orderBy('number');

        if ($this->writing) {
            $query->lockForUpdate();
        }

        $parts = [];

        foreach ($query->get() as $row) {
            $parts[] = new SitemapPart(
                $this->text($row->source ?? null) ?? '',
                $this->whole($row->number ?? null),
                $this->text($row->starts_at ?? null),
                $this->whole($row->urls ?? null),
                $this->text($row->lastmod ?? null),
            );
        }

        return $parts;
    }

    public function body(string $source, int $number): ?string
    {
        return $this->text($this->rows()->where('source', $source)->where('number', $number)->value('body'));
    }

    public function name(string $source, int $number): string
    {
        return $source.'-'.$number.'.xml';
    }

    public function begin(): void
    {
        $this->database()->beginTransaction();
        $this->writing = true;
    }

    public function put(SitemapPart $part): void
    {
        $this->database()->table($this->table)->updateOrInsert(
            [...$this->scope, 'source' => $part->source, 'number' => $part->number],
            ['starts_at' => $part->startsAt, 'urls' => $part->urls, 'lastmod' => $part->lastModified, 'body' => (string) $part->body],
        );
    }

    public function trim(string $source, int $parts): void
    {
        $this->rows()->where('source', $source)->where('number', '>', $parts)->delete();
    }

    public function commit(Closure $entry): void
    {
        $this->writing = false;
        $this->database()->commit();
    }

    public function rollBack(): void
    {
        $this->writing = false;
        $this->database()->rollBack();
    }

    private function rows(): Builder
    {
        return $this->database()->table($this->table)->where($this->scope);
    }

    private function database(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function whole(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
