<?php

declare(strict_types=1);

namespace Polyslug\Contracts;

/**
 * Declares the locales a record is SERVED under, when that is not the same set as the locales
 * it holds a slug for.
 *
 * Polyslug derives every URL set — the hreflang links, the `<head>` tags, the sitemap entries —
 * from `slugLocales()`, which reads the slug rows. For most models that is correct: a record
 * with a German and an English slug is reachable in German and English.
 *
 * It stops being correct as soon as ONE slug is served under SEVERAL addresses. A project may
 * pin each slug to a single locale deliberately and still route every record under a locale
 * prefix. Slug sources are often single-language user content such as a name or a title, and
 * minting a per-locale slug for the same text only fragments the canonical URL:
 *
 *     /u/lena       (en, also x-default)
 *     /de/u/lena    (de)
 *
 * `slugLocales()` reports one entry there and always will, so the second address appears in no
 * hreflang set and in no sitemap. Nothing goes red. The address is simply never announced, and
 * that is the expensive direction: hreflang in a page head is read only after the page has been
 * fetched, so an address no sitemap names and no crawled page links may never be fetched.
 *
 * The distinction is missing from the model, not from the configuration. Adding a second slug
 * row per record would express it, at the price of duplicating identical text and splitting the
 * canonical URL, which is the very thing the project decided against.
 *
 * OPT-IN, like BulkIdentityEncoder and StoresTokensPerRecord: a model that does not implement
 * this contract keeps deriving its locales from its slug rows, unchanged. Implement it only
 * where the two sets genuinely differ.
 *
 * A locale listed here still passes through `polyslugIsRoutable()`, so gating is unaffected,
 * and its route key resolves through the ordinary missing-locale fallback
 * (`polyslug.locale.missing`), which is what lets one slug serve several addresses.
 */
interface ProvidesAddressLocales
{
    /**
     * The locales this record is served under, in the order they should be announced.
     *
     * @return list<string>
     */
    public function polyslugAddressLocales(): array;
}
