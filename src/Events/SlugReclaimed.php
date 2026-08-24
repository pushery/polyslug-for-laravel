<?php

declare(strict_types=1);

namespace Polyslug\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched when a `reclaimActive` model takes a slug away from the record that was
 * actively holding it.
 *
 * ⚠️ THE DISPLACED RECORD IS LEFT WITHOUT A CURRENT SLUG FOR THAT LOCALE, and this event
 * is how a consumer finds out. Its row is retired, not deleted, so its old URL keeps
 * resolving and 301s — but until its own source is synced it has no canonical URL of its
 * own. That is inherent to a takeover: the package cannot know what the displaced record
 * should be called instead, only its own source can say.
 *
 * The previous owner is named by type and key rather than handed over as a model. Loading
 * it would cost a query on every takeover, and it would bypass whatever resolution gate
 * that model has — the caller is better placed to decide both.
 */
final readonly class SlugReclaimed
{
    public function __construct(
        public Model $claimant,
        public string $locale,
        public string $slug,
        public string $previousOwnerType,
        public string $previousOwnerId,
    ) {}
}
