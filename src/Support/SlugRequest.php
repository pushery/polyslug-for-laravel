<?php

declare(strict_types=1);

namespace Polyslug\Support;

/**
 * The inputs a SlugGenerator needs to produce a unique slug: the source text, the
 * morph type and uniqueness scope it competes within, its locale, and the id of the
 * model itself (so its own current slug is not counted as a collision).
 */
final readonly class SlugRequest
{
    /**
     * @param  list<string>|null  $reserved  The reserved words FOR THIS MODEL — the inherited
     *                                       list after the model's own polyslugReservedWords()
     *                                       has had its say. null means the caller resolved
     *                                       none and the generator falls back to the inherited
     *                                       list, which is what a hand-built request gets, so
     *                                       this parameter stays purely additive.
     */
    public function __construct(
        public string $source,
        public string $sluggableType,
        public string $locale,
        public string $scope = '',
        public ?string $exceptId = null,
        public ?array $reserved = null,
    ) {}
}
