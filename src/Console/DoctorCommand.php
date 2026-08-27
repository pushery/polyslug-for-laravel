<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Encoders\RandomTokenEncoder;
use Polyslug\Encoders\SequentialTokenEncoder;
use Polyslug\Support\TokenAlphabet;
use ReflectionMethod;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:doctor';

    /** @var string */
    protected $description = 'Diagnose the Polyslug setup: encoder config, token-space headroom, the uniqueness-guaranteeing indexes, and models that never narrowed their resolution gate.';

    public function handle(): int
    {
        $encodersOk = $this->checkEncoders();
        $schemesOk = $this->checkTokenSchemes();
        $indexesOk = $this->checkIndexes();

        // Only once BOTH have passed, because reporting how full a token space is means
        // resolving the encoder and the short-link scheme — the very things those two
        // checks just said may be unbuildable. A diagnostic that dies on the fault it was
        // run to find is worse than one that does not look.
        if ($encodersOk && $schemesOk) {
            $this->checkTokenSpace();
        }

        $gatesOk = $this->checkResolutionGates();
        $this->checkUrlResolver();

        if (! $encodersOk || ! $schemesOk || ! $indexesOk || ! $gatesOk) {
            $this->error('Polyslug: one or more checks failed.');

            return self::FAILURE;
        }

        $this->info('Polyslug: all checks passed.');

        return self::SUCCESS;
    }

    /**
     * Report whether a PolyslugUrlResolver is bound.
     *
     * The one class a consuming application has to write itself — the package cannot know
     * the host's route structure — and the single most common thing to be missing, because
     * two of the three features built on it fail SILENTLY without it.
     *
     * Reported rather than failed, and the distinction is real: an application that uses
     * neither short links nor sitemaps nor the head integration needs no resolver at all,
     * and failing its doctor run over an unused contract would train people to ignore the
     * command. What the report removes is the guessing. `polyslug:sitemap` already names the
     * contract when it cannot build a URL; `/go` cannot, because its answer has to stay a
     * plain 404 — telling a missing binding apart from an unknown token would also tell an
     * unknown token apart from a hidden record, and that is an existence oracle. So this is
     * the only place that can say it out loud.
     */
    private function checkUrlResolver(): void
    {
        if (Container::getInstance()->bound(PolyslugUrlResolver::class)) {
            $this->line('  ✓ a PolyslugUrlResolver is bound — short links, sitemaps and canonical tags can build URLs.');

            return;
        }

        $this->line('  ! no PolyslugUrlResolver is bound. Every /go short link answers 404, the sitemap');
        $this->line('    command refuses to run, and no canonical or hreflang tag is written. Bind one in');
        $this->line('    a service provider if you use any of those; ignore this line if you use none.');
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

    /**
     * Prove the configured token settings can actually build a scheme.
     *
     * A length of zero, a length past what a counted scheme can reach, an alphabet with a
     * repeated character or a `/` in it — each is refused by the scheme that receives it,
     * and without this check that refusal arrives the first time a URL is RENDERED. Which is
     * to say: on a page, in production, for a setting somebody changed and deployed with a
     * green test suite, because nothing in a test suite renders a URL for a model that does
     * not exist yet.
     *
     * All three are built even when the application uses one, because a wrong value in an
     * unused section is still wrong and costs nothing to name — and "unused" is a property
     * of today's config, not of tomorrow's.
     */
    private function checkTokenSchemes(): bool
    {
        $ok = true;

        foreach ([RandomTokenEncoder::class, SequentialTokenEncoder::class, TokenScheme::class] as $abstract) {
            try {
                Container::getInstance()->make($abstract);
            } catch (InvalidArgumentException $exception) {
                $this->line(sprintf('  ✗ %s', $exception->getMessage()));
                $ok = false;
            }
        }

        if ($ok) {
            $this->line('  ✓ token schemes are valid.');
        }

        return $ok;
    }

    /**
     * Report how full each token space is, before it fills.
     *
     * THIS IS THE ONE CHECK THAT WARNS ABOUT SOMETHING NOTHING ELSE CAN SEE. A short token
     * length is a supported choice, and it stays a good one right up until the space behind
     * it is used up. Nothing goes wrong at that point either — a scheme whose length keeps
     * colliding yields to one character more — but the URLs quietly get longer, and an
     * operator who picked four characters for a printed code deserves to hear about it
     * before the printed codes stop matching the new ones.
     *
     * Reported, never failed. A space at 90% is not a defect: on a counted scheme it is
     * exactly what is supposed to happen, since counting fills a width completely before
     * moving on. What the line removes is the version of that nobody can see.
     *
     * Measured against the tokens that EXIST rather than the ones configured, and grouped by
     * their real length, because a table that has outlived a setting change legitimately
     * holds a mix — and it is the shortest, fullest group that matters.
     */
    private function checkTokenSpace(): void
    {
        $spaces = [
            'identity tokens' => ['polyslug_tokens', $this->identityAlphabet()],
            'short links' => ['polyslug_short_links', Container::getInstance()->make(TokenScheme::class)->alphabet()],
        ];

        foreach ($spaces as $label => [$table, $alphabet]) {
            if (! Schema::hasTable($table)) {
                $this->line(sprintf('  ! [%s] is missing — run the migrations.', $table));

                continue;
            }

            foreach ($this->tokenCounts($table) as $length => $issued) {
                $space = $alphabet->spaceFor($length);
                $used = $issued / $space;

                // Below a quarter there is nothing to say, and saying it anyway is how a
                // diagnostic becomes noise an operator learns to scroll past.
                if ($used < 0.25) {
                    continue;
                }

                $this->line(sprintf(
                    '  ! %s: %s of %s %d-character tokens are taken (%d%%).',
                    $label,
                    number_format($issued),
                    $this->approximate($space),
                    $length,
                    (int) round($used * 100),
                ));
                $this->line(sprintf('    New tokens widen to %d characters as this fills.', $length + 1));
            }
        }

        $this->line('  ✓ token spaces reported.');
    }

    /**
     * The alphabet the CONFIGURED identity encoder counts in.
     *
     * A Sqids, UUID, ULID or raw-id encoder stores nothing in polyslug_tokens, so there is no
     * space of its own to measure — but the table may still hold rows from a stored-token
     * encoder that was configured before it, and those are exactly the ones worth reporting.
     * The default alphabet is the right yardstick for them: it is what every shipped scheme
     * uses unless an application says otherwise, and an application that said otherwise is
     * one whose encoder answers here.
     */
    private function identityAlphabet(): TokenAlphabet
    {
        $encoder = Container::getInstance()->make(IdentityEncoder::class);

        return $encoder instanceof RandomTokenEncoder || $encoder instanceof SequentialTokenEncoder
            ? $encoder->scheme()->alphabet()
            : new TokenAlphabet;
    }

    /**
     * How many tokens of each length a table holds, shortest first.
     *
     * groupByRaw over the expression rather than over its alias: MySQL under
     * ONLY_FULL_GROUP_BY is the strict one here, and repeating the expression is portable
     * where an alias is a dialect question. length() is characters on PostgreSQL and bytes on
     * MySQL, which agree because a token alphabet is URL-unreserved and therefore ASCII.
     *
     * @return array<int, int>
     */
    private function tokenCounts(string $table): array
    {
        $counts = [];

        foreach (DB::table($table)->selectRaw('length(token) as token_length, count(*) as total')->groupByRaw('length(token)')->get() as $row) {
            $length = is_numeric($row->token_length ?? null) ? (int) $row->token_length : 0;
            $total = is_numeric($row->total ?? null) ? (int) $row->total : 0;

            if ($length > 0) {
                $counts[$length] = $total;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** A token space as something an operator can read — 1,296 stays exact, 8.0e24 does not pretend to be. */
    private function approximate(float $space): string
    {
        return $space < 1.0e9 ? number_format($space) : sprintf('%.1e', $space);
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
