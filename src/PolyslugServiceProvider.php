<?php

declare(strict_types=1);

namespace Polyslug;

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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'polyslug');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'polyslug');
        $this->loadRoutesFrom(__DIR__.'/../routes/polyslug.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('polyslug.canonical', EnsureCanonicalSlug::class);
        $router->bind('polyslug', function (string $value, Route $route): Model {
            $type = $route->parameter('type');

            return app(PolyslugResolver::class)->resolve(is_string($type) ? $type : '', $value) ?? abort(404);
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
        $this->publishes([
            __DIR__.'/../config/polyslug.php' => config_path('polyslug.php'),
        ], 'polyslug-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'polyslug-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/polyslug'),
        ], 'polyslug-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/polyslug'),
        ], 'polyslug-lang');
    }
}
