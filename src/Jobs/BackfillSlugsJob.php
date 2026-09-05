<?php

declare(strict_types=1);

namespace Polyslug\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;

/**
 * Backfills the current slug for one keyset chunk of a model's rows. Dispatched by
 * `polyslug:backfill --queue` so huge tables are slugged across queue workers instead
 * of one long synchronous run. Carries only scalars (class + keys + locale, plus the
 * routing below), so it serializes without SerializesModels and re-queries the rows on
 * the worker.
 *
 * ROUTABLE THROUGH PLAIN PROPERTIES, and deliberately not through Queueable. That trait
 * lives in illuminate/bus, which this package does not require, and it is not needed:
 * Dispatcher::dispatchToQueue and pushCommandToQueue resolve the connection and queue
 * through ReadsClassAttributes::getAttributeValue(), which falls back to a public property
 * of that name. Queue::createPayload reads `tries` and `timeout` the same way. Read at the
 * framework source rather than assumed, because "the trait is how you do this" is the
 * obvious reading and would have added a dependency for nothing.
 *
 * Why it matters here rather than in general: a backfill walks an entire table. Left on the
 * default queue it sits in front of every password reset and order confirmation the
 * application has, for as long as the table takes.
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
        public ?string $connection = null,
        public ?string $queue = null,
        public ?int $tries = null,
        public ?int $timeout = null,
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
