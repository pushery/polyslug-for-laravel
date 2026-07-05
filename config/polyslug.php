<?php

declare(strict_types=1);

use Polyslug\Encoders\SqidsEncoder;

return [

    /*
    |--------------------------------------------------------------------------
    | Identity encoder
    |--------------------------------------------------------------------------
    |
    | Maps a model's key to and from the opaque token embedded in its URL
    | (.../my-title_aB3xK). Must implement Polyslug\Contracts\IdentityEncoder.
    |
    | The default SqidsEncoder is OBFUSCATION, NOT SECURITY: a Sqids token is
    | reversible by anyone who knows the alphabet, and it still reveals row count
    | and growth. Swap it for a leak-free encoder (UUID, ULID, or a random public
    | token) when count or creation time must not be inferable.
    |
    */

    'encoder' => SqidsEncoder::class,

    /*
    |--------------------------------------------------------------------------
    | Sqids options
    |--------------------------------------------------------------------------
    |
    | Used only by the SqidsEncoder. A custom alphabet shuffles the token space
    | per application — set one and keep it STABLE, because changing it changes
    | every previously generated URL. null uses the Sqids default alphabet.
    | min_length pads tokens to at least that many characters (0 = no padding).
    |
    */

    'sqids' => [
        'alphabet' => null,
        'min_length' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy decoders
    |--------------------------------------------------------------------------
    |
    | IdentityEncoder classes to try (in order) when the current encoder cannot
    | decode a token. Lets you migrate encoders without breaking old URLs: switch
    | 'encoder' and list the previous one here — old links still resolve, then the
    | canonical middleware 301s them to the new-format URL. Leave empty otherwise.
    |
    */

    'legacy_decoders' => [],

    /*
    |--------------------------------------------------------------------------
    | Slug write path
    |--------------------------------------------------------------------------
    |
    | Writing a slug demotes the old current row and inserts the new one inside a
    | single transaction. If a concurrent writer claims the same slug (or the
    | one-current-row) in between, the unique index rejects the insert; Polyslug
    | then regenerates against the committed state and retries, up to max_attempts,
    | before throwing Polyslug\Exceptions\CouldNotWriteSlug.
    |
    */

    'write' => [
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale resolution
    |--------------------------------------------------------------------------
    |
    | How the canonical-redirect middleware decides which locale a request is for.
    | 'app' (default) uses the active application locale. 'route' reads a {locale}
    | route segment (route_param) so a /{locale}/... URL is compared against — and
    | redirected to — the RIGHT locale's slug, even when the app locale differs (in
    | CLI, queues, or before a locale-setting middleware runs).
    |
    | 'missing' controls the route key when a locale has no slug yet: 'fallback'
    | uses the fallback locale's slug; 'id-only' emits a slug-less "_TOKEN" key.
    | 'fallback_locale' overrides the app fallback locale (null = use the app's).
    |
    */

    'locale' => [
        'source' => 'app',
        'route_param' => 'locale',
        'missing' => 'fallback',
        'fallback_locale' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | App-wide slugs that may never be assigned to any model — merged with each
    | model's own #[Polyslug(reserved: [...])]. Use it to keep generated slugs from
    | shadowing real routes or sensitive words (login, admin, api, cart, ...). A
    | reserved base is suffixed (admin → admin-2) just like any other collision.
    |
    */

    'reserved' => [
        'global' => [],
        'from_routes' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gone & superseded content
    |--------------------------------------------------------------------------
    |
    | For content that no longer lives at its URL. A model whose polyslugIsGone()
    | is true returns `status` (410 Gone by default — a fast de-index signal, unlike
    | a soft-404). A model whose polyslugSupersededBy() returns another model 301s
    | (redirect_status) to that successor's canonical URL, preserving link equity
    | (e.g. a discontinued product → its replacement).
    |
    */

    'gone' => [
        'status' => 410,
        'redirect_status' => 301,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect analytics
    |--------------------------------------------------------------------------
    |
    | When enabled, the canonical middleware dispatches a Polyslug\Events\SlugRedirected
    | event on every self-healing redirect. Listen for it to measure link rot, warm a
    | cache, or purge a CDN — off by default, and fired only for the redirect (never a
    | synchronous write on the hot path unless your listener adds one).
    |
    */

    'analytics' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap
    |--------------------------------------------------------------------------
    |
    | The sluggable models `polyslug:sitemap` includes. Each must be an Eloquent
    | model implementing Sluggable. Bind a Polyslug\Contracts\SitemapUrlResolver in
    | the container to tell the command how to build an absolute URL per model+locale.
    |
    */

    'sitemap' => [
        'types' => [
            // \App\Models\Page::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical redirect
    |--------------------------------------------------------------------------
    |
    | Status for the self-healing redirect from a stale slug to the canonical URL
    | (GET/HEAD only). 301 is permanent; 308 also preserves the HTTP method; use
    | 302/307 while a slug is still volatile so the redirect is not cached.
    |
    */

    'redirect' => [
        'status' => 301,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic type registry
    |--------------------------------------------------------------------------
    |
    | Maps a URL {type} segment to a sluggable model for the polymorphic resolver
    | (a single /{type}/{slug} route serving every content type). Each value must
    | be an Eloquent model implementing Polyslug\Contracts\Sluggable.
    |
    */

    'types' => [
        // 'page' => \App\Models\Page::class,
    ],

];
