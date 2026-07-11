<?php

declare(strict_types=1);

namespace Polyslug;

use Illuminate\Support\Arr;
use Polyslug\Attributes\Polyslug as PolyslugAttribute;
use Polyslug\Enums\TransliterationProfile;
use Polyslug\Exceptions\MisconfiguredPolyslug;

/**
 * The resolved slug configuration for a model, normalized from the #[Polyslug]
 * attribute or a polyslug() method override.
 */
final readonly class PolyslugConfig
{
    /**
     * @param  list<string>  $source
     * @param  list<string>  $scope
     * @param  list<string>  $reserved
     * @param  array<string, mixed>  $encoderOptions
     */
    public function __construct(
        public array $source,
        public string $separator,
        public TransliterationProfile $transliterate,
        public ?int $maxLength,
        public bool $unique,
        public array $scope,
        public array $reserved,
        public bool $immutable,
        public ?string $encoder = null,
        public string $onDelete = 'keep',
        public string $emptyFallback = 'id-only',
        public array $encoderOptions = [],
        public string $unicode = 'ascii',
        public bool $idLess = false,
    ) {
        // An idLess URL is the slug alone, so an idLess model resolves BY its slug and the
        // slug must stay unique. `unique: false` (records may share a slug) is therefore only
        // valid for a non-idLess model, whose encoded id disambiguates.
        if ($this->idLess && ! $this->unique) {
            throw MisconfiguredPolyslug::idLessRequiresUnique();
        }
    }

    public static function fromAttribute(PolyslugAttribute $attribute): self
    {
        return new self(
            source: array_values(Arr::wrap($attribute->source)),
            separator: $attribute->separator,
            transliterate: $attribute->transliterate,
            maxLength: $attribute->maxLength,
            unique: $attribute->unique,
            scope: $attribute->scope === null ? [] : array_values(Arr::wrap($attribute->scope)),
            reserved: $attribute->reserved,
            immutable: $attribute->immutable,
            encoder: $attribute->encoder,
            onDelete: $attribute->onDelete,
            emptyFallback: $attribute->emptyFallback,
            encoderOptions: $attribute->encoderOptions,
            unicode: $attribute->unicode,
            idLess: $attribute->idLess,
        );
    }
}
