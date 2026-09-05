<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

use Illuminate\Support\HtmlString;

/**
 * Implemented by Eloquent models that carry Polyslug slugs. Pair it with the
 * HasPolyslug trait, which provides these methods, and the #[Polyslug] attribute.
 */
interface Sluggable
{
    /**
     * Whether this model (optionally for a locale) should be advertised in hreflang sets
     * and sitemaps — override to keep unpublished models/locales out. The resolution-time
     * gate is polyslugResolveQuery() (provided by the HasPolyslug trait): override it to
     * constrain which rows a slug may resolve to (the required cross-tenant isolation contract).
     */
    public function polyslugIsRoutable(?string $locale = null): bool;

    /** A model this one has been superseded by — its URL 301s to the successor's canonical (null = not superseded). */
    public function polyslugSupersededBy(): ?Sluggable;

    /** Whether this model is permanently gone — its URL returns the configured gone status (410 by default). */
    public function polyslugIsGone(): bool;

    /** The current slug for the given (or active) locale, or null if none exists yet. */
    public function currentSlug(?string $locale = null): ?string;

    /**
     * The route key for the given (or active) locale.
     *
     * "{slug}_{encodedId}" normally; for an idLess model it is the slug (or nested path)
     * ALONE — no delimiter and no token. That is not an edge case to look up elsewhere:
     * the shape of the value this returns depends on the model's configuration.
     */
    public function polyslugRouteKey(?string $locale = null): string;

    /** The route key for an EXPLICIT locale — never reads the ambient app locale (for sitemaps, backfill, CLI, and locale-aware routing). */
    public function polyslugRouteKeyForLocale(string $locale): string;

    /** The parent in a nested hierarchy — override to compose ancestor slugs into the route-key path. null (default) = not nested. Pair with a scope on the parent key for per-parent slug uniqueness. */
    public function polyslugParent(): ?Sluggable;

    /** The slash-joined slug path (ancestors + own) for the given (or active) locale; just the own slug when not nested. maxDepth bounds recursion so a parent cycle can't loop forever. */
    public function polyslugPath(?string $locale = null, int $maxDepth = 20): string;

    /** A stable short-link token for this model + locale; route Polyslug\Http\Controllers\ShortLinkController at /go/{token} to 301 it to the current canonical URL (survives renames). */
    public function shortLink(?string $locale = null): string;

    /** Resolve this model type by primary key THROUGH the resolution gate (polyslugResolveQuery), so tenant/visibility scoping applies on every path — including /go. `mixed` because the key arrives untyped: as a route parameter or as a decoded token; the implementation narrows it. */
    public function polyslugResolveByKey(mixed $key): ?static;

    /**
     * Re-resolve THIS instance through its own resolution gate: the same row when the
     * caller may see it, null when it may not.
     *
     * For a model that arrived from route binding this is a no-op — binding already went
     * through the gate. It exists for a model that did NOT arrive that way, and the one
     * the package itself hands around is the successor from polyslugSupersededBy(): that
     * comes from a return value, not from a resolution, so nothing has asked whether the
     * requester may see it before its slug is rendered into a Location header.
     */
    public function polyslugResolveSelf(): ?static;

    /** Generate or refresh the current slug from the model's source (idempotent). */
    public function polyslugSync(?string $locale = null): void;

    /** Generate or refresh the current slug WITHOUT taking a name another record still holds — the backfill counterpart to polyslugSync(). Identical on a model that is not `reclaimActive`. */
    public function polyslugSeed(?string $locale = null): void;

    /** Apply the delete policy (cascade slug rows on hard/force delete; release on soft-delete if configured). */
    public function polyslugOnDeleted(): void;

    /** Set the current slug for a locale from the given source, or the model's own source. */
    public function setSlug(string $locale, ?string $source = null): void;

    /** Write a slug WITHOUT taking a name another record still holds: the active holder blocks, so the newcomer yields with a suffix instead of displacing it. Identical to setSlug() on a model that is not `reclaimActive`. */
    public function seedSlug(string $locale, ?string $source = null): void;

    /**
     * The locales that currently have a slug.
     *
     * @return list<string>
     */
    public function slugLocales(): array;

    /**
     * Superseded slugs for a locale, newest first.
     *
     * @return list<string>
     */
    public function slugHistory(?string $locale = null): array;

    /**
     * An absolute URL per locale that has a current slug AND is routable, built by the
     * resolver.
     *
     * The routability filter is part of the contract, not an implementation detail: a
     * locale that polyslugIsRoutable() excludes is absent from this set, and therefore
     * from the hreflang set and the sitemap built on top of it.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function polyslugUrls(callable $urlUsing): array;

    /**
     * The reciprocal hreflang set (self-referential, plus x-default).
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function hreflangLinks(callable $urlUsing, ?string $xDefault = null): array;

    /**
     * Rendered <link rel="alternate" hreflang="..."> tags for this model.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function hreflangTags(callable $urlUsing, ?string $xDefault = null): HtmlString;

    /**
     * Rendered <xhtml:link rel="alternate" hreflang="..."> alternates for a sitemap entry.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function sitemapAlternateTags(callable $urlUsing, ?string $xDefault = null): HtmlString;
}
