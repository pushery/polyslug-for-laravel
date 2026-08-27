<?php

declare(strict_types=1);

namespace Polyslug;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Head\HeadManager;
use Override;
use Polyslug\Console\BackfillSlugsCommand;
use Polyslug\Console\DoctorCommand;
use Polyslug\Console\MakePolyslugCommand;
use Polyslug\Console\SitemapCommand;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\Sluggable;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Encoders\RandomTokenEncoder;
use Polyslug\Encoders\SequentialTokenEncoder;
use Polyslug\Encoders\SqidsEncoder;
use Polyslug\Generators\DefaultSlugGenerator;
use Polyslug\Http\Middleware\EnsureCanonicalSlug;
use Polyslug\Support\PolyslugHead;
use Polyslug\Support\PolyslugOpenGraphLocales;
use Polyslug\Support\RandomTokenScheme;
use Polyslug\Support\SequentialTokenScheme;
use Polyslug\Support\TokenAlphabet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PolyslugServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    /**
     * The random short-link width when `polyslug.short_links.length` says nothing.
     *
     * Ten rather than the identity token's sixteen, because a short link is meant to be typed
     * off a page and read out loud — and it is not the thing a record is resolved by, so it
     * carries less. It lives here rather than on RandomTokenScheme because it is a property of
     * this feature, not of the scheme, which serves both stores.
     */
    private const int SHORT_LINK_LENGTH = 10;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/polyslug.php', 'polyslug');

        $this->app->singleton(IdentityEncoder::class, static function (Application $app): IdentityEncoder {
            // The fallback matters as much as the config file: mergeConfigFrom covers a
            // consumer who never published the config, but a config PUBLISHED from an older
            // version has no 'encoder' key at all and lands here. Both paths must arrive at
            // the leak-free encoder, or "safe by default" holds only for new installs.
            $encoder = $app->make(ConfigRepository::class)->get('polyslug.encoder', RandomTokenEncoder::class);
            $instance = is_string($encoder) ? $app->make($encoder) : null;

            if (! $instance instanceof IdentityEncoder) {
                throw new InvalidArgumentException(sprintf(
                    'The [polyslug.encoder] config must be a class-string implementing %s.',
                    IdentityEncoder::class,
                ));
            }

            return $instance;
        });

        // SINGLETONS, unlike the Sqids binding below, and the difference is state rather
        // than style: a stored-token encoder memoizes what it has read, so a rendered list
        // costs one query instead of one per row. Resolved per call it would hand back an
        // empty memo every time, and polyslugPreload() — which groups models by the object
        // identity of their encoder — would fill a memo that is discarded before the first
        // route key is built. That is what a model naming one of these explicitly through
        // `#[Polyslug(encoder: …)]` used to get: the class was bound nowhere, so the
        // container built a fresh instance for every resolution, while the default path
        // (through the IdentityEncoder singleton) kept exactly one.
        $this->app->singleton(RandomTokenEncoder::class, static function (Application $app): RandomTokenEncoder {
            $options = self::tokenOptions($app, 'polyslug.random_token');

            return new RandomTokenEncoder($options['length'] ?? RandomTokenEncoder::DEFAULT_LENGTH, $options['alphabet']);
        });

        $this->app->singleton(SequentialTokenEncoder::class, static function (Application $app): SequentialTokenEncoder {
            $options = self::tokenOptions($app, 'polyslug.sequential_token');

            return new SequentialTokenEncoder($options['length'] ?? SequentialTokenEncoder::DEFAULT_LENGTH, $options['alphabet']);
        });

        // The scheme the /go/{token} short link draws from — its own token space, and its
        // own setting, because a printed or spoken link wants a different trade from the
        // token inside every URL. Bound rather than built inline so a consumer can replace
        // it wholesale with a scheme of their own.
        $this->app->singleton(TokenScheme::class, static function (Application $app): TokenScheme {
            $options = self::tokenOptions($app, 'polyslug.short_links');
            $scheme = $app->make(ConfigRepository::class)->get('polyslug.short_links.scheme', 'random');

            // The default length is the SCHEME's, not the section's, and that is not a detail.
            // One setting serves both schemes here, and the sensible starting width differs by
            // an order of magnitude: ten random characters is a short link, ten COUNTED ones
            // is `0000000000` for the first record. A section-level default would hand that to
            // anyone who switched the scheme and did not also think to change the length.
            return $scheme === 'sequential'
                ? new SequentialTokenScheme($options['length'] ?? SequentialTokenScheme::DEFAULT_LENGTH, $options['alphabet'])
                : new RandomTokenScheme($options['length'] ?? self::SHORT_LINK_LENGTH, $options['alphabet']);
        });

        $this->app->bind(SqidsEncoder::class, static function (Application $app): SqidsEncoder {
            $config = $app->make(ConfigRepository::class);
            $alphabet = $config->get('polyslug.sqids.alphabet');
            $minLength = $config->get('polyslug.sqids.min_length', 0);

            return new SqidsEncoder(
                is_string($alphabet) ? $alphabet : null,
                is_int($minLength) ? $minLength : 0,
            );
        });

        $this->app->bind(SlugGenerator::class, DefaultSlugGenerator::class);
    }

    /**
     * The length and alphabet under a token-settings config key, defaulted rather than
     * demanded.
     *
     * A published config file from an older version has none of these keys at all, so every
     * read has to survive their absence — the same reason the encoder binding above carries
     * its own fallback. A malformed value takes the default here instead of being coerced;
     * the schemes then validate what they are actually handed, so a bad alphabet is refused
     * by the one place that knows what makes an alphabet legal.
     *
     * @return array{length: int|null, alphabet: TokenAlphabet|null}
     */
    private static function tokenOptions(Application $app, string $key): array
    {
        $config = $app->make(ConfigRepository::class);
        $length = $config->get($key.'.length');
        $alphabet = $config->get($key.'.alphabet');

        return [
            'length' => is_int($length) ? $length : null,
            'alphabet' => is_string($alphabet) ? new TokenAlphabet($alphabet) : null,
        ];
    }

    public function boot(): void
    {
        // No views, no translations and no routes. This package renders nothing and
        // emits no user-facing text (its exception messages and console output address
        // developers), and it deliberately registers no route of its own: the one
        // controller it ships, ShortLinkController, is mounted by the consuming
        // application at a path of its choosing — its docblock and the Sluggable
        // contract both say so. A routes file with nothing in it is scaffolding that
        // costs a require on every boot and documents a route that does not exist.

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('polyslug.canonical', EnsureCanonicalSlug::class);
        $router->bind('polyslug', function (string $value, Route $route): Model {
            $type = $route->parameter('type');

            return Container::getInstance()->make(PolyslugResolver::class)->resolve(is_string($type) ? $type : '', $value) ?? throw new NotFoundHttpException;
        });

        // Route::polyslug('/pages/{page}', ...) wires SubstituteBindings THEN polyslug.canonical
        // in the correct order, so self-heal can never silently no-op from a mis-ordered stack.
        //
        // $action is Closure|array|string|null rather than `callable`, which is what this
        // said before and was wider than the framework. The one callable shape that is
        // none of those three — an invokable OBJECT — does not reach a controller: it
        // fatals inside Router::createRoute with "Cannot use object of type ... as array"
        // at REGISTRATION time. Advertising it was an invitation to a fatal, and it is
        // also the type RouteRegistrar declares for its own get(), so both chain
        // positions now say the same thing.
        $router->macro('polyslug', fn (string $uri, array|string|Closure|null $action = null): Route => $router->get($uri, $action)->middleware([SubstituteBindings::class, 'polyslug.canonical']));

        // And on the REGISTRAR, because Macroable declares $macros inside the trait: Router
        // and RouteRegistrar keep separate bags, so registering on one leaves the other
        // throwing BadMethodCallException. RouteRegistrar is what every chained form lands
        // on — Route::middleware(...)->polyslug(...), Route::prefix(...)->polyslug(...) —
        // and that is the shape a consumer reaches for the moment the route needs auth or a
        // prefix. `get` is a passthru verb there and returns the Route, so the body is the
        // same one line and the group's own attributes are preserved.
        RouteRegistrar::macro('polyslug', function (string $uri, array|string|Closure|null $action = null): Route {
            /** @var RouteRegistrar $this */
            return $this->get($uri, $action)->middleware([SubstituteBindings::class, 'polyslug.canonical']);
        });

        Blade::directive(
            'polyslugHreflang',
            static fn (string $expression): string => "<?php echo \\Polyslug\\Support\\PolyslugBlade::hreflang({$expression}); ?>",
        );

        $this->registerHeadIntegration();

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillSlugsCommand::class, DoctorCommand::class, MakePolyslugCommand::class, SitemapCommand::class]);
            $this->registerPublishing();
        }
    }

    /**
     * laravel/head is an OPTIONAL companion, never a dependency. When the host has it,
     * a Polyslug model can describe the four <head> facts Polyslug alone knows:
     *
     *     Head::polyslug($page);   // canonical + hreflang + og:locale + indexability
     *
     * Everything below is skipped without it, PolyslugHead is then never loaded, and
     * the package behaves exactly as it did before. That is why the class reference is
     * guarded rather than merely imported — an import that only resolves because the
     * class happens to sit in the vendor tree is the defect DeclaredDependencyContractTest
     * exists to catch, one ecosystem over.
     */
    private function registerHeadIntegration(): void
    {
        // Written as a positive guard rather than an early return on purpose: the
        // negative branch is UNREACHABLE here, because laravel/head is a dev dependency
        // and therefore always installed in this suite. An early `return;` would be a
        // permanently uncovered line, and the honest fix for a line no test can reach is
        // to not write it — not to annotate it away.
        if (class_exists(HeadManager::class)) {
            HeadManager::macro('polyslug', function (Sluggable $model, ?string $locale = null): HeadManager {
                /** @var HeadManager $this */
                return PolyslugHead::apply($this, $model, $locale);
            });

            // og:locale:alternate is the one Open Graph property a multilingual page
            // repeats, and laravel/head's meta() cannot repeat a key — it stores tags in
            // an array keyed by attribute|key|media, so the second call overwrites the
            // first. TagRegistry::extend() is the sanctioned answer, and the only one:
            // both render paths iterate the REGISTRY, so a builder that is not registered
            // is never rendered no matter what data it holds.
            //
            // callAfterResolving, not make(): laravel/head may register after this
            // provider boots, and forcing HeadManager into existence here would resolve a
            // singleton the request may never use. It also covers the opposite order —
            // the callback fires immediately when the manager is already resolved. The
            // registration is idempotent on laravel/head's side.
            $this->callAfterResolving(
                HeadManager::class,
                static fn (HeadManager $head): HeadManager => $head->extend(PolyslugOpenGraphLocales::class),
            );
        }
    }

    private function registerPublishing(): void
    {
        // Resolve publish targets through the Application contract's path methods
        // (available via illuminate/contracts), NOT the config_path()/database_path()/
        // resource_path()/lang_path() global helpers. Those are Foundation helpers,
        // shipped ONLY with laravel/framework — which this package does not require —
        // so the helper form would freeze a wrong dependency contract and fatal in a
        // non-Foundation host. The method form is behavior-identical.
        //
        // Each group carries the bare 'polyslug' umbrella tag on top of its specific
        // one, so `vendor:publish --tag="polyslug"` publishes every resource at once —
        // the tag convention Laravel's official package skeleton establishes.
        $this->publishes([
            __DIR__.'/../config/polyslug.php' => $this->app->configPath('polyslug.php'),
        ], ['polyslug', 'polyslug-config']);

        // publishesMigrations(), not publishes(): it rewrites the bundled
        // 0001_01_01_000000 ordering prefix to the publish date, so a published
        // migration sorts AFTER the host app's existing migrations instead of before
        // all of them (where it would run before the tables it may reference exist).
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], ['polyslug', 'polyslug-migrations']);

        // There is deliberately no views or lang group: the package ships neither.
        // Both existed until 0.4.0 and contained only the generator's placeholders
        // ("This is an example Polyslug translation string."), so publishing them
        // copied scaffolding into consuming applications.
    }
}
