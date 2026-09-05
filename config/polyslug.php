<?php

declare(strict_types=1);

use Polyslug\Encoders\RandomTokenEncoder;

return [

    /*
    |--------------------------------------------------------------------------
    | Identity encoder
    |--------------------------------------------------------------------------
    |
    | Maps a model's key to and from the opaque token embedded in its URL
    | (.../my-title_aB3xK). Must implement Polyslug\Contracts\IdentityEncoder.
    |
    | The default is RandomTokenEncoder: it stores an unguessable random token per
    | key, so the URL reveals nothing — not the primary key, not the row count, not
    | how fast the table grows. It costs one row in polyslug_tokens per record, and
    | one INSERT the first time a given record's URL is rendered.
    |
    | SqidsEncoder is fully supported and is the right choice when you want short,
    | deterministic tokens and the id space is not sensitive — but it is OBFUSCATION,
    | NOT SECURITY: a Sqids token decodes straight back to the primary key, so every
    | URL leaks the key, the creation order and the growth rate, and an unguessable
    | URL becomes a constructible one. That is a decision to make deliberately, which
    | is why it is no longer what you get by not deciding.
    |
    | SWITCHING ENCODERS WITHOUT BREAKING LINKS. Put the old encoder in
    | legacy_decoders below: existing URLs keep resolving through it, and the
    | canonical middleware 301s them to the new format as they are visited. Old links
    | self-heal instead of breaking.
    |
    |     'encoder' => RandomTokenEncoder::class,
    |     'legacy_decoders' => [SqidsEncoder::class],
    |
    */

    'encoder' => RandomTokenEncoder::class,

    /*
    |--------------------------------------------------------------------------
    | Token schemes — how a generated token looks
    |--------------------------------------------------------------------------
    |
    | Two stores in this package hand out tokens: the identity token inside every URL
    | (RandomTokenEncoder / SequentialTokenEncoder above) and the /go/{token} short
    | link. Both draw from one of two schemes, and both read their settings here.
    |
    | 'length' is a FLOOR in both schemes, never a ceiling. A width whose space fills
    | up yields to one character more rather than failing to issue a URL — so a short
    | setting is a real choice and not a trap that surfaces months later as a 500 on
    | a GET. 'alphabet' is null for the default base-36 set (0-9 a-z); pass your own
    | to change the character set, which must be URL-unreserved and repeat nothing.
    |
    | RANDOM (the default) draws every token at random, so the URL says nothing about
    | the record: not its key, not its age, not how many others exist. This is the
    | scheme to keep whenever the URL is itself part of the access story — a share
    | link, an unlisted page, anything enumeration-sensitive.
    |
    |     length   token space          example
    |     4        ~1.7 million         /lists/k3f9
    |     6        ~2.2 billion         /lists/k3f9dl
    |     8        ~2.8 trillion        /lists/k3f9dlq7
    |     10       ~3.7 quadrillion     /lists/k3f9dlq7xm
    |     16       ~8.0 x 10^24         /lists/k3f9dlq7xm2bv4tc  (default)
    |
    | SEQUENTIAL hands out the shortest token not yet taken — 0, 1, … z, then 00, 01
    | — which is the shortest URL that can exist for a given number of records, and
    | what a link shortener is usually after. In exchange it is COMPLETELY
    | PREDICTABLE: the token after k3f8 is k3f9, so the whole set can be walked, and
    | the token reports how many records exist and roughly when this one appeared.
    | Fine for public content nobody is hiding; wrong for anything the URL alone
    | protects. A minimum length does not change that — it moves where the counting
    | starts, it does not scatter what follows. Switch to it with:
    |
    |     'encoder' => Polyslug\Encoders\SequentialTokenEncoder::class,
    |
    | Changing either setting is safe at any time: tokens are stored, never recomputed
    | from the key, so existing URLs keep resolving and only new records use the new
    | setting.
    |
    */

    'random_token' => [
        'length' => 16,
        'alphabet' => null,
    ],

    'sequential_token' => [
        'length' => 1,
        'alphabet' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Short links
    |--------------------------------------------------------------------------
    |
    | The /go/{token} link produced by $model->shortLink(). It is a separate token
    | space from the identity token above and takes its own scheme, because the two
    | are used differently: a short link is printed, spoken and put on a QR code, so
    | it is the one most likely to want 'sequential' and a small length.
    |
    | 'scheme' is 'random' or 'sequential'; 'length' and 'alphabet' mean exactly what
    | they mean above. A null length takes the SCHEME's own default — 10 characters
    | random, 1 counted — rather than one number for both, because ten random
    | characters is a short link while ten counted ones is `0000000000`.
    |
    */

    'short_links' => [
        'scheme' => 'random',
        'length' => null,
        'alphabet' => null,
    ],

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
    | 'from_routes' additionally reserves the FIRST static segment of every registered
    | route ('/admin/users' reserves 'admin'), so a generated slug cannot shadow a real
    | route without you having to list them by hand. Off by default: it reads the route
    | table at generation time, and on an application with many routes that is work you
    | should opt into rather than inherit.
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
    | model implementing Sluggable. Bind a Polyslug\Contracts\PolyslugUrlResolver in
    | the container to tell the command how to build an absolute URL per model+locale.
    |
    | 'max_urls' and 'max_bytes' are the sitemap protocol's own ceilings for ONE file:
    | 50,000 URLs and 50 MB uncompressed. Past either one the command writes numbered
    | parts next to --path and puts a <sitemapindex> at --path itself, so the limit is
    | never something the operator has to notice. Lower them if a CDN or a search
    | console you use wants smaller files; raising them past the protocol produces a
    | document that engines reject.
    |
    */

    'sitemap' => [
        'types' => [
            // \App\Models\Page::class,
        ],

        'max_urls' => 50_000,
        'max_bytes' => 50 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backfill
    |--------------------------------------------------------------------------
    |
    | Where `polyslug:backfill --queue` puts its jobs. A backfill walks an entire
    | table, so on the default queue it sits in front of every password reset and
    | order confirmation the application has, for as long as that takes. Name a
    | queue your workers treat as bulk, and the rest of the app keeps moving.
    |
    | null leaves the framework's own default in place for each. Both can be
    | overridden per run with --on-queue= and --on-connection=. 'tries' and
    | 'timeout' are the job's, not the worker's -- a chunk that re-queries its rows
    | is safe to retry, and a long chunk needs a timeout that admits it.
    |
    */

    'backfill' => [
        'connection' => null,
        'queue' => null,
        'tries' => null,
        'timeout' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Open Graph
    |--------------------------------------------------------------------------
    |
    | Open Graph writes a locale as language_TERRITORY ('en_US', 'pt_BR'). A plain
    | 'en' is outside that format, and a scraper that cannot parse the value does
    | not read a language from it — it falls back to its own default. So a locale
    | with no territory gets NO og:locale tag rather than an unparseable one.
    |
    | The territory is not something the package can invent: 'en' is en_US to one
    | site and en_GB to another, and asserting either would announce a regional
    | variant nobody configured. Name the pairs you want here. Locales that already
    | carry a territory ('pt_BR', 'de-AT') need no entry.
    |
    */

    'open_graph' => [
        'locale_map' => [
            // 'en' => 'en_US',
            // 'de' => 'de_DE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical redirect
    |--------------------------------------------------------------------------
    |
    | Status for the self-healing redirect from a stale slug to the canonical URL
    | (GET/HEAD only). 301 is permanent; use 302/307 while a slug is still volatile
    | so the redirect is not cached. 308 is accepted too, and since only GET and HEAD
    | are ever redirected, its method-preserving guarantee changes nothing here.
    |
    */

    'redirect' => [
        'status' => 301,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug-only resolution: require a scope
    |--------------------------------------------------------------------------
    |
    | A slug-only URL carries no id, so the slug alone has to identify the record.
    | On a model scoped with #[Polyslug(scope: ...)] that is only true WITHIN a
    | scope: the unique index is scope-bound, so /@alice/toolkit and /@bob/toolkit
    | may both hold "toolkit" legitimately.
    |
    | The resolve-query gate does not separate them. It answers a different
    | question — what the ENVIRONMENT (session, tenant, request context) says is
    | visible — while a scope in a path segment is an argument of the resolution.
    | Hand it over by overriding polyslugResolutionScope() on the model.
    |
    | Turn this on and a scoped model whose caller names no scope is REFUSED
    | instead of resolving to whichever row sorts first. Off by default because
    | switching it on refuses every scoped model that does not answer yet — the
    | right end state, but not something an update should do to you silently.
    |
    */

    'resolution' => [
        'require_scope' => false,
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
