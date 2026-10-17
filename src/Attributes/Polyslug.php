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
     * @param  bool  $unique  true (default) appends a numeric suffix (-2, -3, …) on a collision. false lets records share a slug — no suffix, and the rows are excluded from the uniqueness index (a non-idLess URL resolves by the encoded id, not the slug). Combining false with idLess is rejected (MisconfiguredPolyslug), since an idLess model resolves by its slug.
     * @param  string|list<string>|null  $scope  Column(s) that scope uniqueness (e.g. tenant_id).
     * @param  list<string>  $reserved  Slugs that may never be assigned.
     * @param  string|null  $encoder  A fully-qualified IdentityEncoder class to override the global encoder for this model only.
     * @param  string  $onDelete  'keep' (default) leaves slugs in place when the model is soft-deleted; 'release' frees them for reuse.
     * @param  string  $emptyFallback  When the source produces no slug (e.g. a CJK/emoji-only title): 'id-only' (default) stores an empty slug so the URL is just the encoded id and the save never fails, and on an idLess model, whose URL has no id, makes the encoded id the slug; 'throw' raises CouldNotGenerateSlug.
     * @param  array<string, mixed>  $encoderOptions  Per-model encoder options giving this model its own token space. Which keys are read depends on the effective encoder: SqidsEncoder takes 'alphabet' and 'min_length', and one left out takes the Sqids default rather than polyslug.sqids; RandomTokenEncoder and SequentialTokenEncoder take 'length' and 'alphabet', and one left out keeps the application's setting. Every other encoder ignores them.
     * @param  string  $unicode  Slug character set: 'ascii' (default) transliterates to ASCII; 'native' keeps Unicode letters/numbers (non-Latin markets), lower-cased at generation so the case-insensitive unique index behaves identically on PostgreSQL and SQLite.
     * @param  bool  $reclaim  Only meaningful with idLess. false (default) keeps a retired slug reserved forever, so an old URL can never be handed to a different model. true releases it: another record may claim it, and the URL then serves the new owner. Use it only when the name comes from a source that reassigns it anyway (a mirrored account, an external registry) — on app-owned slugs it turns a rename into a way to take over someone else's published URL. Rejected without idLess (MisconfiguredPolyslug), where it would silently do nothing.
     * @param  bool  $reclaimActive  Extends $reclaim from retired names to a name a different record still holds. false (default) lets the active holder block, so the newcomer gets a counter suffix. true retires the holder's row and hands the name over. It exists for a mirror whose events can arrive out of order: when the source reassigns a name and the release event is lost or late, the previous owner still holds it actively, and the newcomer would otherwise be named `x-2` forever while the source says `x`. The displaced record is left with no current slug for that locale until its own source is synced — listen for SlugReclaimed. Requires $reclaim (and therefore idLess); rejected otherwise (MisconfiguredPolyslug).
     * @param  bool  $idLess  Drop the "_{encodedId}" suffix — the URL is the slug alone (/blog/hello-world). Resolution is then by slug: the current slug resolves directly, a superseded slug still resolves and 301s to the current one, and retired slugs stay reserved so an old URL can never point at a different model. The slug must be unique per (type, locale, scope).
     * @param  bool  $stored  true (default) writes each slug to `polyslug_slugs` and keeps the former ones as history. false computes the slug from the source on every call and writes nothing: a renamed record's old URL still resolves by its token, and the canonical middleware 301s it to the new one. For a record that can be rebuilt at any time, such as a read model projected from another system. Requires `unique: false`, since nothing keeps a computed slug unique, and excludes `idLess` and `immutable` (MisconfiguredPolyslug). With `slugless: true` it writes no row at all, and `unique` plays no part.
     * @param  string|null  $identity  A column whose value is the token of the URL, in place of the encoded primary key: `{slug}_{value}`. A URL then resolves by that column, through the resolution gate, so a record keeps its address when it is written again under another key. The value takes letters, digits and hyphens. Excludes `encoder`, `encoderOptions` and `idLess` (MisconfiguredPolyslug). Polyslug\Support\TailTokens builds such a value from the end of a longer id.
     * @param  string|null  $identityOf  The column the token in `identity` was taken from, such as the id of another system whose end Polyslug\Support\TailTokens made the token. A URL whose token the identity column does not hold then also resolves by that id, written whole the way TailTokens::whole() writes it: the record is looked up under every token TailTokens::apart() can have given it, so the lookup stays on the identity column, and the canonical middleware moves the URL to the token of today. Requires `identity` (MisconfiguredPolyslug).
     * @param  string|null  $identitySecond  The column of a second id, which tells apart two records that carry the same id in `identityOf`, as the second ids handed to TailTokens::apart() do. A URL with the second id after the whole id resolves to the record both name. Requires `identityOf` (MisconfiguredPolyslug).
     * @param  bool  $slugless  The mirror image of idLess: drop the slug, keep the identity — the URL is the encoded token alone (/lists/k3f9dlq7). For records whose URL is meant to be short and opaque rather than descriptive: a share link, a QR target, a per-user list nobody searches for by name. No slug is generated, so no source column is read, no collision suffix is ever appended, and renaming the record cannot change the URL. Old descriptive URLs keep resolving after a switch to slugless and are 301ed to the short form by the canonical middleware. Combining it with idLess is rejected (MisconfiguredPolyslug) — together they would leave nothing to route on. Token length is the encoder's business: set polyslug.random_token.length, or $encoderOptions['length'] for this model alone.
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
        public bool $preserveCase = false,
        public bool $stored = true,
        public ?string $identity = null,
        public ?string $identityOf = null,
        public ?string $identitySecond = null,
    ) {}
}
