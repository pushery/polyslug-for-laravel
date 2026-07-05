<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;

final class CouldNotGenerateSlug extends RuntimeException
{
    public function __construct(string $source)
    {
        parent::__construct("Could not generate a non-empty slug from source [{$source}].");
    }
}
