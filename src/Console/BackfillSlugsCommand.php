<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;
use Polyslug\Jobs\BackfillSlugsJob;

final class BackfillSlugsCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:backfill {model : The fully-qualified sluggable model class} {--locale= : Locale to backfill (defaults to the app locale)} {--queue : Dispatch chunked queued jobs instead of running inline} {--chunk=1000 : Rows per queued job}';

    /** @var string */
    protected $description = "Backfill current slugs for a sluggable model's existing rows.";

    public function handle(): int
    {
        $model = $this->argument('model');

        if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Sluggable::class, true)) {
            $this->error('The [model] argument must be an Eloquent model class implementing '.Sluggable::class.'.');

            return self::FAILURE;
        }

        $localeOption = $this->option('locale');
        $locale = is_string($localeOption) && $localeOption !== '' ? $localeOption : null;

        if ($this->option('queue') === true) {
            $dispatched = 0;
            $dispatcher = Container::getInstance()->make(Dispatcher::class);

            $model::query()->chunkById($this->chunkSize(), function (Collection $rows) use ($model, $locale, $dispatcher, &$dispatched): void {
                $dispatcher->dispatch(new BackfillSlugsJob($model, array_values($rows->modelKeys()), $locale));
                $dispatched++;
            });

            $this->info("Dispatched {$dispatched} backfill job(s) for [{$model}].");

            return self::SUCCESS;
        }

        $backfilled = 0;

        foreach ($model::query()->lazyById() as $row) {
            if ($row instanceof Sluggable && $row->currentSlug($locale) === null) {
                $row->polyslugSeed($locale);
                $backfilled++;
            }
        }

        $this->info("Backfilled {$backfilled} slug(s) for [{$model}].");

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $chunk = $this->option('chunk');

        return is_numeric($chunk) && (int) $chunk > 0 ? (int) $chunk : 1000;
    }
}
