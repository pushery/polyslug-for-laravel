<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Polyslug\Concerns\HasPolyslug;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\PolyslugUrlResolver;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Encoders\RandomTokenEncoder;
use Polyslug\Encoders\SequentialTokenEncoder;
use Polyslug\Models\PolyslugShortLink;
use Polyslug\Models\PolyslugSlug;
use Polyslug\Support\StatusSetting;
use Polyslug\Support\TokenAlphabet;
use ReflectionMethod;
use Throwable;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'polyslug:doctor';

    /** @var string */
    protected $description = 'Diagnose the Polyslug setup: encoder config, model replacements, status settings, token-space headroom, the uniqueness-guaranteeing indexes, and models that never narrowed their resolution gate.';

    /**
     * The models a host can replace through polyslug.models: the keys the package itself asks for.
     *
     * @var list<class-string<PolyslugSlug|PolyslugShortLink>>
     */
    private const array REPLACEABLE_MODELS = [PolyslugSlug::class, PolyslugShortLink::class];

    public function handle(): int
    {
        $encodersOk = $this->checkEncoders();
        $schemesOk = $this->checkTokenSchemes();
        $modelsOk = $this->checkModels();
        $statusesOk = $this->checkStatuses();
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

        if (! $encodersOk || ! $schemesOk || ! $modelsOk || ! $statusesOk || ! $indexesOk || ! $gatesOk) {
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
            // keeps pointing at HasPolyslug until someone actually writes their own.
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

        // One short line per finding: the console component wraps long lines, and a
        // wrapped line is one an operator skims past.
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
     *
     * Anything a build throws is reported, not only a refused length or alphabet. A scheme
     * name outside `random` and `sequential` is refused by the binding with a different
     * exception, and a scheme a host binds itself may throw whatever it throws; a check that
     * ended the run there would hide the fault it exists to name, and every check after it.
     */
    private function checkTokenSchemes(): bool
    {
        $ok = true;

        foreach ([RandomTokenEncoder::class, SequentialTokenEncoder::class, TokenScheme::class] as $abstract) {
            try {
                Container::getInstance()->make($abstract);
            } catch (Throwable $exception) {
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
        $identity = $this->identityAlphabet();

        // Short links are counted where the short-link model in use keeps them, which a host can
        // move with polyslug.models; identity tokens always live on the default connection.
        $shortLinks = PolyslugShortLink::model();
        $shortLinkModel = new $shortLinks;
        $spaces = [
            'identity tokens' => [DB::connection(), 'polyslug_tokens', $identity],
            'short links' => [$shortLinkModel->getConnection(), $shortLinkModel->getTable(), Container::getInstance()->make(TokenScheme::class)->alphabet()],
        ];

        foreach ($spaces as $label => [$connection, $table, $alphabet]) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                $this->line(sprintf('  ! [%s] is missing — run the migrations.', $table));

                continue;
            }

            $this->reportFill($label, $alphabet, $this->tokenCounts($connection, $table));
        }

        if (Schema::hasTable('polyslug_tokens')) {
            foreach ($this->modelTokenSpaces($identity) as [$types, $alphabet]) {
                $this->reportFill('identity tokens of '.implode(', ', $types), $alphabet, $this->tokenCounts(DB::connection(), 'polyslug_tokens', $types));
            }
        }

        $this->line('  ✓ token spaces reported.');
    }

    /**
     * One line per width that is at least a quarter full, and what happens as it fills.
     *
     * @param  array<int, int>  $counts
     */
    private function reportFill(string $label, TokenAlphabet $alphabet, array $counts): void
    {
        foreach ($counts as $length => $issued) {
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

    /**
     * The token spaces models chose for themselves, each with the types that draw from it.
     *
     * A model whose #[Polyslug] encoderOptions name an alphabet of its own draws from a space
     * the report on the application's alphabet does not measure: there, nine tokens are a
     * rounding error, while the model's own nine-token space is full. So every type that holds
     * tokens is asked for the alphabet its encoder actually uses, and types that share an
     * alphabet are reported together, because a token is unique across the whole table and
     * they fill one space between them. A type on the application's alphabet is already in
     * the report above.
     *
     * @return list<array{0: list<string>, 1: TokenAlphabet}>
     */
    private function modelTokenSpaces(TokenAlphabet $identity): array
    {
        $spaces = [];
        $types = array_filter(DB::table('polyslug_tokens')->where('key_type', '!=', '')->distinct()->orderBy('key_type')->pluck('key_type')->all(), is_string(...));

        foreach ($types as $type) {
            $alphabet = $this->modelTokenAlphabet($type);

            if ($alphabet instanceof TokenAlphabet && $alphabet->alphabet !== $identity->alphabet) {
                $spaces[$alphabet->alphabet] ??= [[], $alphabet];
                $spaces[$alphabet->alphabet][0][] = $type;
            }
        }

        return array_values($spaces);
    }

    /**
     * The alphabet a type's stored tokens are drawn from, or null when it has none of its own.
     *
     * Read from the encoder the model builds for itself, through the method its own reads and
     * writes go through, so the report follows whatever that method decides. A type that names
     * no model this package manages, such as a class removed since its rows were written, has
     * no encoder to ask; its rows are still counted on the application's alphabet.
     */
    private function modelTokenAlphabet(string $type): ?TokenAlphabet
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true) || ! method_exists($class, 'polyslugEncoder')) {
            return null;
        }

        try {
            $encoder = new ReflectionMethod($class, 'polyslugEncoder')->invoke(new $class);
        } catch (Throwable $exception) {
            $this->line(sprintf('  ! [%s] cannot build its encoder, so its own token space is not reported: %s', $class, $exception->getMessage()));

            return null;
        }

        return $encoder instanceof RandomTokenEncoder || $encoder instanceof SequentialTokenEncoder ? $encoder->scheme()->alphabet() : null;
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
     * @param  list<string>|null  $types  only the rows of these key types, when given
     * @return array<int, int>
     */
    private function tokenCounts(Connection $connection, string $table, ?array $types = null): array
    {
        $counts = [];
        $query = $connection->table($table)->selectRaw('length(token) as token_length, count(*) as total')->groupByRaw('length(token)');

        if ($types !== null) {
            $query->whereIn('key_type', $types);
        }

        foreach ($query->get() as $row) {
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

                continue;
            }

            // Built the way a request builds it, because a valid class can still refuse to be
            // constructed: SqidsEncoder needs the bcmath or gmp extension and throws without
            // both, on the first token a request renders or, as a legacy decoder, reads.
            try {
                Container::getInstance()->make($class);
            } catch (Throwable $e) {
                $this->line(sprintf('  ✗ [%s] cannot be built: %s', $class, $e->getMessage()));
                $ok = false;
            }
        }

        if ($ok) {
            $this->line('  ✓ encoder and legacy decoders are valid.');
        }

        return $ok;
    }

    /**
     * Every entry of polyslug.models, held to the rule the seam applies when it reads one.
     *
     * Replaceable::model() ignores an entry it cannot obey and takes the package class, so a
     * request keeps working and the host loses the customization without a sign: the scopes,
     * casts and relations of the subclass never run. An ignored entry therefore fails this check,
     * as a misspelled encoder class does. The verdict is the seam's own answer, model() asked for
     * the key, so this check and the class the package really uses cannot disagree; the reason
     * only explains the verdict. A key the package never asks for is an entry nothing reads.
     */
    private function checkModels(): bool
    {
        $models = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.models', []);

        if (! is_array($models) || $models === []) {
            return true;
        }

        $ok = true;

        foreach ($models as $key => $configured) {
            $shown = is_string($configured) ? $configured : get_debug_type($configured);

            if (! in_array($key, self::REPLACEABLE_MODELS, true)) {
                $this->line(sprintf(
                    '  ✗ polyslug.models: [%s] is not a model Polyslug replaces, so its entry [%s] is never read. The keys are [%s].',
                    $key,
                    $shown,
                    implode('] and [', self::REPLACEABLE_MODELS),
                ));
                $ok = false;

                continue;
            }

            $used = $key::model();

            if ($used === $configured) {
                continue;
            }

            if (! is_string($configured)) {
                $reason = 'is not a class name';
            } elseif (! class_exists($configured)) {
                $reason = 'does not exist';
            } elseif (! is_subclass_of($configured, $key)) {
                $reason = 'does not extend it';
            } else {
                $reason = 'cannot be instantiated';
            }

            $this->line(sprintf('  ✗ polyslug.models: [%s] for [%s] %s, so Polyslug uses [%s].', $shown, $key, $reason, $used));
            $ok = false;
        }

        if ($ok) {
            $this->line('  ✓ model replacements are in use.');
        }

        return $ok;
    }

    /**
     * The four status settings, each held to the kind of status it takes.
     *
     * A value of the wrong kind, or one that is no integer, is not obeyed: the canonical middleware
     * sends the default instead (see StatusSetting), which keeps every request answering. It is
     * still a setting the host made that has no effect, and this line is where that is said.
     */
    private function checkStatuses(): bool
    {
        $config = Container::getInstance()->make(ConfigRepository::class);
        $ok = true;

        foreach (StatusSetting::ALL as $setting) {
            $value = $config->get($setting['key']);

            // An unset value is the default, which is of its kind by definition.
            $accepted = $value === null || ($setting['redirect'] ? StatusSetting::isRedirect($value) : StatusSetting::isError($value));

            if ($accepted) {
                continue;
            }

            if (! is_int($value)) {
                $reason = 'not an integer';
            } elseif ($setting['redirect']) {
                $reason = 'not a redirect status (301, 302, 303, 307 or 308)';
            } else {
                $reason = 'not a client error status (400 to 499)';
            }

            $this->line(sprintf(
                '  ✗ %s: [%s] is %s, so Polyslug %s %d.',
                $setting['key'],
                // A string in quotes, so `'302'` reads as the string it is; a number as written;
                // anything else by its type.
                is_string($value) ? "'".$value."'" : (is_int($value) || is_float($value) ? (string) $value : get_debug_type($value)),
                $reason,
                $setting['redirect'] ? 'redirects with' : 'answers with',
                $setting['default'],
            ));
            $ok = false;
        }

        if ($ok) {
            $this->line('  ✓ status settings are in range.');
        }

        return $ok;
    }

    /**
     * The indexes that hold the guarantees, by name AND by kind.
     *
     * A name alone is not the guarantee: an index of the same name that is not unique, left by a
     * hand-made rebuild or a rollback that failed halfway, lets two current slugs collide while
     * the name is still there.
     *
     * Read on the connection and table of the slug model in use, which is the package's own
     * unless polyslug.models replaces it: every slug is written there, so that is where the
     * indexes have to hold.
     */
    private function checkIndexes(): bool
    {
        $slugs = PolyslugSlug::model();
        $model = new $slugs;
        $unique = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable()) as $index) {
            $unique[$index['name']] = $index['unique'];
        }

        $ok = true;

        foreach (['polyslug_slugs_current_unique', 'polyslug_slugs_one_current'] as $name) {
            if (! array_key_exists($name, $unique)) {
                $this->line(sprintf('  ✗ unique index [%s] is missing — run the migrations.', $name));
                $ok = false;
            } elseif (! $unique[$name]) {
                $this->line(sprintf('  ✗ index [%s] exists but is not unique, so two current slugs can collide — drop it and run the migrations.', $name));
                $ok = false;
            }
        }

        if ($ok) {
            $this->line('  ✓ uniqueness indexes are present.');
        }

        return $ok;
    }
}
