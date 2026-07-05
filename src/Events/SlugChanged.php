<?php

declare(strict_types=1);

namespace Polyslug\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched when a model's current slug is created or changed for a locale.
 * `previous` is null on the first generation, otherwise the superseded slug.
 */
final readonly class SlugChanged
{
    public function __construct(
        public Model $model,
        public string $locale,
        public string $slug,
        public ?string $previous,
    ) {}
}
