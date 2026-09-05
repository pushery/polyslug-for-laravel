<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;
use Polyslug\Jobs\BackfillSlugsJob;

final class BackfillSlugsCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:backfill {model : The fully-qualified sluggable model class} {--locale= : Locale to backfill (defaults to the app locale)} {--queue : Dispatch chunked queued jobs instead of running inline} {--chunk=1000 : Rows per queued job} {--on-queue= : Queue name for the dispatched jobs (default: polyslug.backfill.queue)} {--on-connection= : Queue connection for the dispatched jobs (default: polyslug.backfill.connection)}';

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

            $connection = $this->routing('on-connection', 'connection');
            $queue = $this->routing('on-queue', 'queue');
            $tries = $this->positiveInt('polyslug.backfill.tries');
            $timeout = $this->positiveInt('polyslug.backfill.timeout');

            $model::query()->chunkById($this->chunkSize(), function (Collection $rows) use ($model, $locale, $dispatcher, $connection, $queue, $tries, $timeout, &$dispatched): void {
                $dispatcher->dispatch(new BackfillSlugsJob(
                    $model,
                    array_values($rows->modelKeys()),
                    $locale,
                    $connection,
                    $queue,
                    $tries,
                    $timeout,
                ));
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

    /**
     * The option, or the configured default, or nothing.
     *
     * Three states rather than two, and the empty string is the one that matters: an option
     * left unset arrives as null, but `--on-queue=` with nothing after it arrives as `''` --
     * which the dispatcher would take as a queue named the empty string rather than as "use
     * the default".
     */
    private function routing(string $option, string $key): ?string
    {
        $given = $this->option($option);

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $configured = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.backfill.'.$key);

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * A configured positive integer, or null to leave the framework's own default in place.
     */
    private function positiveInt(string $key): ?int
    {
        $value = Container::getInstance()->make(ConfigRepository::class)->get($key);

        return is_int($value) && $value > 0 ? $value : null;
    }
}
