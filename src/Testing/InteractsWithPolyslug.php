<?php

declare(strict_types=1);

namespace Polyslug\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase;
use Polyslug\Contracts\Sluggable;

/**
 * Test assertions for sluggable models. Mix into a Laravel/Testbench test case:
 * `uses(InteractsWithPolyslug::class);` (Pest) or `use InteractsWithPolyslug;` (PHPUnit).
 *
 * THE ONE SHIPPED FILE ALLOWED TO IMPORT Illuminate\Foundation, and the allowance is
 * narrow. `Illuminate\Foundation` has no split package — it exists only inside
 * laravel/framework, which this package deliberately does not require. Two things make
 * it safe here and nowhere else: the import feeds only the `@mixin` below, so nothing
 * ever loads the class at runtime (a `use` statement is an alias, not an include); and
 * the trait runs exclusively from a consumer's own test suite, where the full framework
 * is present by definition. Any OTHER shipped file importing Foundation is a real defect
 * that would fatal on a lean install; this file is the single exception, and it stays the
 * single one.
 *
 * @mixin TestCase
 */
trait InteractsWithPolyslug
{
    /** Assert a GET to $from self-heals to the canonical $to with the given status. */
    protected function assertSlugRedirects(string $from, string $to, int $status = 301): void
    {
        $this->get($from)->assertStatus($status)->assertRedirect($to);
    }

    /** Assert the model's current slug for a locale. */
    protected function assertHasCurrentSlug(Sluggable $model, string $slug, ?string $locale = null): void
    {
        $this->assertSame($slug, $model->currentSlug($locale));
    }

    /**
     * Assert a route key resolves to the expected model.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertSlugResolves(string $modelClass, string $routeKey, int|string $expectedKey): void
    {
        $resolved = (new $modelClass)->resolveRouteBinding($routeKey);

        $this->assertNotNull($resolved, "Expected [{$routeKey}] to resolve for [{$modelClass}].");
        $this->assertSame($expectedKey, $resolved->getKey());
    }

    /**
     * Assert a token does not resolve (a clean 404, not a fuzzy match).
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertSlugNotResolvable(string $modelClass, string $token): void
    {
        $this->assertNull(
            (new $modelClass)->resolveRouteBinding($token),
            "Expected [{$token}] to be unresolvable for [{$modelClass}].",
        );
    }
}
