<?php

declare(strict_types=1);

namespace Polyslug;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Override;
use Polyslug\Console\BackfillSlugsCommand;
use Polyslug\Console\DoctorCommand;
use Polyslug\Console\MakePolyslugCommand;
use Polyslug\Console\SitemapCommand;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Encoders\SqidsEncoder;
use Polyslug\Generators\DefaultSlugGenerator;
use Polyslug\Http\Middleware\EnsureCanonicalSlug;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PolyslugServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/polyslug.php', 'polyslug');

        $this->app->singleton(IdentityEncoder::class, static function (Application $app): IdentityEncoder {
            $encoder = $app->make(ConfigRepository::class)->get('polyslug.encoder', SqidsEncoder::class);
            $instance = is_string($encoder) ? $app->make($encoder) : null;

            if (! $instance instanceof IdentityEncoder) {
                throw new InvalidArgumentException(sprintf(
                    'The [polyslug.encoder] config must be a class-string implementing %s.',
                    IdentityEncoder::class,
                ));
            }

            return $instance;
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

    public function boot(): void
    {
        // No views and no translations: this package routes and resolves, it renders
        // nothing and emits no user-facing text. Its exception messages and console
        // output are addressed to developers. Registering an empty namespace would
        // publish scaffolding that documents nothing.
        $this->loadRoutesFrom(__DIR__.'/../routes/polyslug.php');

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
        $router->macro('polyslug', fn (string $uri, array|string|callable|null $action = null): Route => $router->get($uri, $action)->middleware([SubstituteBindings::class, 'polyslug.canonical']));

        Blade::directive(
            'polyslugHreflang',
            static fn (string $expression): string => "<?php echo \\Polyslug\\Support\\PolyslugBlade::hreflang({$expression}); ?>",
        );

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillSlugsCommand::class, DoctorCommand::class, MakePolyslugCommand::class, SitemapCommand::class]);
            $this->registerPublishing();
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
