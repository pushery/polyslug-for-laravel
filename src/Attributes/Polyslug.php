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
     * @param  string|list<string>  $source  Column(s) the slug is built from. Omit it (and only then) on a slugless model, whose URL carries no slug to build.
     * @param  bool  $unique  true (default) appends a numeric suffix (-2, -3, …) on a collision. false lets records share a slug — no suffix, and the rows are excluded from the uniqueness index (a non-idLess URL resolves by the encoded id, not the slug). Combining false with idLess is rejected (MisconfiguredPolyslug), since an idLess model resolves BY its slug.
     * @param  string|list<string>|null  $scope  Column(s) that scope uniqueness (e.g. tenant_id).
     * @param  list<string>  $reserved  Slugs that may never be assigned.
     * @param  string|null  $encoder  A fully-qualified IdentityEncoder class to override the global encoder for this model only.
     * @param  string  $onDelete  'keep' (default) leaves slugs in place when the model is soft-deleted; 'release' frees them for reuse.
     * @param  string  $emptyFallback  When the source produces no slug (e.g. a CJK/emoji-only title): 'id-only' (default) stores an empty slug so the URL is just the encoded id and the save never fails; 'throw' raises CouldNotGenerateSlug.
     * @param  array<string, mixed>  $encoderOptions  Per-model SqidsEncoder options ('alphabet', 'min_length') giving this model its own token space. Ignored unless the effective encoder is SqidsEncoder.
     * @param  string  $unicode  Slug character set: 'ascii' (default) transliterates to ASCII; 'native' keeps Unicode letters/numbers (non-Latin markets), lower-cased at generation so the case-insensitive unique index behaves identically on PostgreSQL and SQLite.
     * @param  bool  $reclaim  Only meaningful with idLess. false (default) keeps a retired slug reserved forever, so an old URL can never be handed to a different model. true releases it: another record may claim it, and the URL then serves the NEW owner. Use it ONLY when the name comes from a source that reassigns it anyway (a mirrored account, an external registry) — on app-owned slugs it turns a rename into a way to take over someone else's published URL. Rejected without idLess (MisconfiguredPolyslug), where it would silently do nothing.
     * @param  bool  $reclaimActive  Extends $reclaim from retired names to a name a DIFFERENT record still holds. false (default) lets the active holder block, so the newcomer gets a counter suffix. true retires the holder's row and hands the name over. It exists for a mirror whose events can arrive out of order: when the source reassigns a name and the release event is lost or late, the previous owner still holds it actively, and the newcomer would otherwise be named `x-2` forever while the source says `x`. ⚠️ The displaced record is left with NO current slug for that locale until its own source is synced — listen for SlugReclaimed. Requires $reclaim (and therefore idLess); rejected otherwise (MisconfiguredPolyslug).
     * @param  bool  $idLess  Drop the "_{encodedId}" suffix — the URL is the slug alone (/blog/hello-world). Resolution is then by slug: the current slug resolves directly, a superseded slug still resolves and 301s to the current one, and retired slugs stay reserved so an old URL can never point at a different model. The slug must be unique per (type, locale, scope).
     * @param  bool  $slugless  The mirror image of idLess: drop the SLUG, keep the identity — the URL is the encoded token alone (/lists/k3f9dlq7). For records whose URL is meant to be short and opaque rather than descriptive: a share link, a QR target, a per-user list nobody searches for by name. No slug is generated, so no source column is read, no collision suffix is ever appended, and renaming the record cannot change the URL. Old descriptive URLs keep resolving after a switch to slugless and are 301ed to the short form by the canonical middleware. Combining it with idLess is rejected (MisconfiguredPolyslug) — together they would leave nothing to route on. Token length is the encoder's business: set polyslug.random_token.length, or $encoderOptions['length'] for this model alone.
     */
    public function __construct(
        public string|array $source = [],
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
        public bool $reclaim = false,
        public bool $reclaimActive = false,
        public bool $slugless = false,
    ) {}
}
