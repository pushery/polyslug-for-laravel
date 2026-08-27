<?php

declare(strict_types=1);

namespace Polyslug\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;

/**
 * Backfills the current slug for one keyset chunk of a model's rows. Dispatched by
 * `polyslug:backfill --queue` so huge tables are slugged across queue workers instead
 * of one long synchronous run. Carries only scalars (class + keys + locale), so it
 * serializes without SerializesModels and re-queries the rows on the worker.
 */
final class BackfillSlugsJob implements ShouldQueue
{
    /**
     * @param  class-string  $model
     * @param  list<int|string>  $keys
     */
    public function __construct(
        public string $model,
        public array $keys,
        public ?string $locale = null,
    ) {}

    public function handle(): void
    {
        $model = $this->model;

        if (! is_a($model, Model::class, true) || ! is_a($model, Sluggable::class, true)) {
            return;
        }

        foreach ($model::query()->whereKey($this->keys)->get() as $row) {
            if ($row instanceof Sluggable && $row->currentSlug($this->locale) === null) {
                $row->polyslugSeed($this->locale);
            }
        }
    }
}
