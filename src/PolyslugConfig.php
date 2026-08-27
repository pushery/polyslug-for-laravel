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
 *
 * THE CONSTRUCTOR IS THE VALIDATION SEAM, and deliberately so: a polyslug() override builds
 * one of these directly, so a rule checked in the attribute instead would hold for a static
 * declaration and not for a per-tenant one.
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
        public bool $reclaim = false,
        public bool $reclaimActive = false,
        public bool $slugless = false,
    ) {
        // An idLess URL is the slug alone, so an idLess model resolves BY its slug and the
        // slug must stay unique. `unique: false` (records may share a slug) is therefore only
        // valid for a non-idLess model, whose encoded id disambiguates.
        if ($this->idLess && ! $this->unique) {
            throw MisconfiguredPolyslug::idLessRequiresUnique();
        }

        // Rejected rather than ignored. On a non-idLess model a retired slug is ALREADY free
        // to reuse — the encoded id disambiguates — so reclaim would change nothing at all.
        // A flag that silently does nothing is worse than one that is refused: whoever set it
        // believes a guarantee has been relaxed, and would only find out otherwise from a
        // collision suffix appearing where they expected a handover.
        if ($this->reclaim && ! $this->idLess) {
            throw MisconfiguredPolyslug::reclaimRequiresIdLess();
        }

        // Refused for the same reason, one step further out. `reclaimActive` widens `reclaim`
        // from retired names to actively held ones; without `reclaim` the retired rows would
        // still block, so a takeover would succeed against a live holder and then be refused
        // by that holder's own history — the least explicable half-behavior of the three.
        if ($this->reclaimActive && ! $this->reclaim) {
            throw MisconfiguredPolyslug::reclaimActiveRequiresReclaim();
        }

        // slugless and idLess are the two halves of the URL grammar, and each drops the
        // other one's half. Together there is nothing left to route on.
        if ($this->slugless && $this->idLess) {
            throw MisconfiguredPolyslug::sluglessExcludesIdLess();
        }

        // The three below would each do NOTHING on a slugless model, and are refused for the
        // reason the reclaim checks give: whoever set one believes a behavior changed, and
        // the only way they would find out otherwise is from the behavior they were trying
        // to change. maxLength is the one worth naming — it trims the SLUG, so on a model
        // whose URL is a token it is the most natural wrong guess about why the URL is long.
        if ($this->slugless && $this->maxLength !== null) {
            throw MisconfiguredPolyslug::sluglessExcludesMaxLength();
        }

        if ($this->slugless && $this->reserved !== []) {
            throw MisconfiguredPolyslug::sluglessExcludesReserved();
        }

        if ($this->slugless && $this->source !== []) {
            throw MisconfiguredPolyslug::sluglessTakesNoSource();
        }

        // Stated here rather than left to the attribute signature, which cannot express it:
        // source has to be optional for a slugless model to declare none, and the moment it
        // is optional, omitting it on an ordinary model becomes a silent empty slug for every
        // record instead of a refusal at the first save.
        if (! $this->slugless && $this->source === []) {
            throw MisconfiguredPolyslug::sourceIsRequired();
        }
    }

    /**
     * Whether this model's slug rows take part in the uniqueness index.
     *
     * Two different reasons land on the same answer, which is why they are resolved once
     * here rather than re-derived at each of the three places that ask. `unique: false` is a
     * consumer saying records may share a slug because the encoded id disambiguates them;
     * `slugless` produces no slug at all, so every record would hold the same empty one and
     * a uniqueness index over that would hand the second record the counter suffix `-2` — a
     * name, in the URL of a model whose entire point is not to have one.
     */
    /**
     * The same configuration, except it will not take a name another record still holds.
     *
     * `reclaimActive` is a property of the MODEL, but taking a name is a property of the
     * WRITE. A webhook carries a handover the source has already made, so taking is correct.
     * A backfill carries no such thing: two existing records wanting one name is a conflict
     * in the data, and whoever takes there decides ownership by row order, silently.
     *
     * Only ever narrowing. Turning reclaimActive ON for one call is not offered, because it
     * requires `reclaim` and would let a caller assemble a combination the config constructor
     * refuses — a validity rule that can be sidestepped per call is not a rule.
     */
    public function withoutActiveReclaim(): self
    {
        if (! $this->reclaimActive) {
            return $this;
        }

        return new self(
            source: $this->source,
            separator: $this->separator,
            transliterate: $this->transliterate,
            maxLength: $this->maxLength,
            unique: $this->unique,
            scope: $this->scope,
            reserved: $this->reserved,
            immutable: $this->immutable,
            encoder: $this->encoder,
            onDelete: $this->onDelete,
            emptyFallback: $this->emptyFallback,
            encoderOptions: $this->encoderOptions,
            unicode: $this->unicode,
            idLess: $this->idLess,
            reclaim: $this->reclaim,
            reclaimActive: false,
            slugless: $this->slugless,
        );
    }

    public function enforcesUniqueSlug(): bool
    {
        return $this->unique && ! $this->slugless;
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
            reclaim: $attribute->reclaim,
            reclaimActive: $attribute->reclaimActive,
            slugless: $attribute->slugless,
        );
    }
}
