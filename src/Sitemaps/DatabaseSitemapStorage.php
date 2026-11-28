<?php

declare(strict_types=1);

namespace Polyslug\Sitemaps;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Polyslug\Contracts\SitemapStorage;
use Polyslug\Contracts\StagesSitemapParts;

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
 *
 * The parts of a cut that runs over several writes wait in the same table, under their source's
 * name with `:staged:` in front. parts() and body() never read them, and promote() serves them in
 * place of the source's parts.
 */
final class DatabaseSitemapStorage implements SitemapStorage, StagesSitemapParts
{
    /** What a staged part's source starts with, so no part that is served is ever one. */
    private const string STAGED = ':staged:';

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
            $source = $this->text($row->source ?? null) ?? '';

            if (str_starts_with($source, self::STAGED)) {
                continue;
            }

            $parts[] = new SitemapPart(
                $source,
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

    public function stage(SitemapPart $part): void
    {
        $this->put(new SitemapPart(self::STAGED.$part->source, $part->number, $part->startsAt, $part->urls, $part->lastModified, $part->body));
    }

    public function staged(string $source): array
    {
        $parts = [];

        foreach ($this->rows()->where('source', self::STAGED.$source)->select(['number', 'starts_at', 'urls', 'lastmod'])->orderBy('number')->get() as $row) {
            $parts[] = new SitemapPart(
                $source,
                $this->whole($row->number ?? null),
                $this->text($row->starts_at ?? null),
                $this->whole($row->urls ?? null),
                $this->text($row->lastmod ?? null),
            );
        }

        return $parts;
    }

    public function promote(string $source, int $parts): void
    {
        $this->rows()->where('source', $source)->delete();
        $this->rows()->where('source', self::STAGED.$source)->where('number', '<=', $parts)->update(['source' => $source]);
        $this->discard($source);
    }

    public function discard(string $source): void
    {
        $this->rows()->where('source', self::STAGED.$source)->delete();
    }

    private function rows(): Builder
    {
        return $this->database()->table($this->table)->where($this->scope);
    }

    private function database(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    /**
     * @param  mixed  $value  a column of a row as the driver answers it, of whatever type the driver gives it
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  mixed  $value  a column of a row as the driver answers it, of whatever type the driver gives it
     */
    private function whole(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
