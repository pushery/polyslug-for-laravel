<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;

final class MissingPolyslugConfig extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public function __construct(string $model)
    {
        parent::__construct(
            "Model [{$model}] uses the HasPolyslug trait but has no #[Polyslug] attribute."
        );
    }
}
