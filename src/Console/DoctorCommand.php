<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Polyslug\Contracts\IdentityEncoder;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:doctor';

    /** @var string */
    protected $description = 'Diagnose the Polyslug setup: encoder config and the uniqueness-guaranteeing indexes.';

    public function handle(): int
    {
        $encodersOk = $this->checkEncoders();
        $indexesOk = $this->checkIndexes();

        if (! $encodersOk || ! $indexesOk) {
            $this->error('Polyslug: one or more checks failed.');

            return self::FAILURE;
        }

        $this->info('Polyslug: all checks passed.');

        return self::SUCCESS;
    }

    private function checkEncoders(): bool
    {
        $legacy = config('polyslug.legacy_decoders', []);
        $classes = array_merge([config('polyslug.encoder')], is_array($legacy) ? $legacy : []);
        $ok = true;

        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_a($class, IdentityEncoder::class, true)) {
                $this->line(sprintf('  ✗ [%s] is not a valid IdentityEncoder.', is_string($class) ? $class : gettype($class)));
                $ok = false;
            }
        }

        if ($ok) {
            $this->line('  ✓ encoder and legacy decoders are valid.');
        }

        return $ok;
    }

    private function checkIndexes(): bool
    {
        $existing = Schema::getIndexListing('polyslug_slugs');
        $ok = true;

        foreach (['polyslug_slugs_current_unique', 'polyslug_slugs_one_current'] as $name) {
            if (! in_array($name, $existing, true)) {
                $this->line(sprintf('  ✗ unique index [%s] is missing — run the migrations.', $name));
                $ok = false;
            }
        }

        if ($ok) {
            $this->line('  ✓ uniqueness indexes are present.');
        }

        return $ok;
    }
}
