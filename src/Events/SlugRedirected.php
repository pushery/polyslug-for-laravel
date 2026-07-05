<?php

declare(strict_types=1);

namespace Polyslug\Events;

use Polyslug\Contracts\Sluggable;

/**
 * Dispatched when the canonical middleware self-heals a stale slug — only when
 * polyslug.analytics.enabled is true. Fire-and-forget: a listener can log it, measure
 * link rot, or purge a CDN. It is keyed on the stable model (not the churny slug), so
 * a rename does not double-count.
 */
final readonly class SlugRedirected
{
    public function __construct(
        public Sluggable $model,
        public string $requested,
        public string $canonicalUrl,
        public string $locale,
        public int $status,
    ) {}
}
