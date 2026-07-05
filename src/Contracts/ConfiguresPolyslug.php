<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Polyslug\PolyslugConfig;

/**
 * Implement on a sluggable model to compute its slug configuration at runtime —
 * per-tenant reserved words, a per-environment encoder, dynamic scope, etc. The
 * returned config takes precedence over the static #[Polyslug] attribute and is
 * resolved fresh on every use (never cached), so it can vary per request/tenant.
 */
interface ConfiguresPolyslug
{
    public function polyslug(): PolyslugConfig;
}
