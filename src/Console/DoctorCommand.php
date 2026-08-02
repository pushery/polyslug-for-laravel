<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Schema;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Contracts\IdentityEncoder;
use ReflectionMethod;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:doctor';

    /** @var string */
    protected $description = 'Diagnose the Polyslug setup: encoder config, the uniqueness-guaranteeing indexes, and models that never narrowed their resolution gate.';

    public function handle(): int
    {
        $encodersOk = $this->checkEncoders();
        $indexesOk = $this->checkIndexes();
        $gatesOk = $this->checkResolutionGates();

        if (! $encodersOk || ! $indexesOk || ! $gatesOk) {
            $this->error('Polyslug: one or more checks failed.');

            return self::FAILURE;
        }

        $this->info('Polyslug: all checks passed.');

        return self::SUCCESS;
    }

    /**
     * Report every registered type that never overrode polyslugResolveQuery().
     *
     * The trait's default gate returns the query untouched, so a slug resolves to ANY
     * row of that type — across tenants, across owners, published or not. That is the
     * right default for genuinely public content and a silent authorization bypass for
     * everything else, and nothing distinguishes the two but the author having read one
     * docblock line.
     *
     * It is reported, never failed: a globally-resolvable model is a legitimate choice.
     * What the check removes is the *invisible* version of that choice — after seeing
     * this line, a maintainer either scopes the model or overrides the gate with an
     * explicit no-op, and either way the decision is now stated somewhere.
     */
    private function checkResolutionGates(): bool
    {
        $types = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.types', []);

        if (! is_array($types) || $types === []) {
            $this->line('  ✓ no polymorphic types registered — no resolution gates to check.');

            return true;
        }

        $traitFile = new ReflectionMethod(HasPolyslug::class, 'polyslugResolveQuery')->getFileName();
        $ungated = [];

        foreach ($types as $class) {
            if (! is_string($class)) {
                continue;
            }
            if (! class_exists($class)) {
                continue;
            }
            if (! method_exists($class, 'polyslugResolveQuery')) {
                continue;
            }
            // "Still the trait's version" is decided by the FILE the method was compiled
            // from, not by getDeclaringClass(): PHP flattens a trait's methods into the
            // using class, so getDeclaringClass() answers with the model for an
            // un-overridden method just as it does for an overridden one. getFileName()
            // keeps pointing at the trait until someone actually writes their own.
            //
            // A model inheriting an override from its own base class therefore also reads
            // as gated, which is right — that is a decision, just made one level up.
            if (new ReflectionMethod($class, 'polyslugResolveQuery')->getFileName() === $traitFile) {
                $ungated[] = $class;
            }
        }

        if ($ungated === []) {
            $this->line('  ✓ every registered type constrains its resolution gate.');

            return true;
        }

        // One short line per finding. The console component wraps long lines, and a
        // wrapped line is one an operator skims past — and one no test can match on.
        foreach ($ungated as $class) {
            $this->line(sprintf('  ! [%s] does not override polyslugResolveQuery().', $class));
        }

        $this->line('    Any slug of those types resolves to any row.');
        $this->line('    Intended (public content)? Override it with `return $query;`.');
        $this->line('    Not intended? A stale slug for a foreign row 301s to that row.');

        return true;
    }

    private function checkEncoders(): bool
    {
        $legacy = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.legacy_decoders', []);
        $classes = array_merge([Container::getInstance()->make(ConfigRepository::class)->get('polyslug.encoder')], is_array($legacy) ? $legacy : []);
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
