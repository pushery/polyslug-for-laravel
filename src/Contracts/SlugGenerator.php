<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Polyslug\Exceptions\CouldNotGenerateSlug;
use Polyslug\PolyslugConfig;
use Polyslug\Support\SlugRequest;

interface SlugGenerator
{
    /**
     * Produce a unique, URL-safe slug for the given request and configuration.
     *
     * @throws CouldNotGenerateSlug when the source yields an empty slug
     */
    public function generate(SlugRequest $request, PolyslugConfig $config): string;
}
