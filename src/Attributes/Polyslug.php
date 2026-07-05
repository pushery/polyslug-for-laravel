<?php

declare(strict_types=1);

namespace Polyslug\Attributes;

use Attribute;
use Polyslug\Enums\TransliterationProfile;

/**
 * Declares how a model produces its slug. Place it on the Eloquent model that uses
 * the HasPolyslug trait. For dynamic (e.g. per-tenant) rules, implement
 * Polyslug\Contracts\ConfiguresPolyslug and return a PolyslugConfig from polyslug() —
 * that is resolved fresh and takes precedence over this attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Polyslug
{
    /**
     * @param  string|list<string>  $source  Column(s) the slug is built from.
     * @param  string|list<string>|null  $scope  Column(s) that scope uniqueness (e.g. tenant_id).
     * @param  list<string>  $reserved  Slugs that may never be assigned.
     * @param  string|null  $encoder  A fully-qualified IdentityEncoder class to override the global encoder for this model only.
     * @param  string  $onDelete  'keep' (default) leaves slugs in place when the model is soft-deleted; 'release' frees them for reuse.
     * @param  string  $emptyFallback  When the source produces no slug (e.g. a CJK/emoji-only title): 'id-only' (default) stores an empty slug so the URL is just the encoded id and the save never fails; 'throw' raises CouldNotGenerateSlug.
     * @param  array<string, mixed>  $encoderOptions  Per-model SqidsEncoder options ('alphabet', 'min_length') giving this model its own token space. Ignored unless the effective encoder is SqidsEncoder.
     * @param  string  $unicode  Slug character set: 'ascii' (default) transliterates to ASCII; 'native' keeps Unicode letters/numbers (non-Latin markets), lower-cased at generation so the case-insensitive unique index behaves identically on PostgreSQL and SQLite.
     * @param  bool  $idLess  Drop the "_{encodedId}" suffix — the URL is the slug alone (/blog/hello-world). Resolution is then by slug: the current slug resolves directly, a superseded slug still resolves and 301s to the current one, and retired slugs stay reserved so an old URL can never point at a different model. The slug must be unique per (type, locale, scope).
     */
    public function __construct(
        public string|array $source,
        public string $separator = '-',
        public TransliterationProfile $transliterate = TransliterationProfile::Simple,
        public ?int $maxLength = null,
        public bool $unique = true,
        public string|array|null $scope = null,
        public array $reserved = [],
        public bool $immutable = false,
        public ?string $encoder = null,
        public string $onDelete = 'keep',
        public string $emptyFallback = 'id-only',
        public array $encoderOptions = [],
        public string $unicode = 'ascii',
        public bool $idLess = false,
    ) {}
}
