<?php

declare(strict_types=1);

namespace Polyslug;

use Closure;
use Polyslug\Contracts\ProvidesAddressLocales;

/**
 * The URL grammar: a routable value is `{slug}_{encodedId}`.
 *
 * The slug is human-readable and may change; the encoded identity is stable. The
 * delimiter is reserved and never valid inside a slug, so the value is split on its
 * LAST occurrence — a slug that (illegally) contains the delimiter still resolves to
 * the identity and is corrected by the canonical redirect.
 */
final class Polyslug
{
    /**
     * The reserved delimiter between a slug and its encoded identity. Never valid
     * inside a slug, which is why splitting on the last occurrence is unambiguous.
     */
    public const string DELIMITER = '_';

    /** A well-formed slug: lowercase alphanumerics in single-hyphen-separated words. */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * Split a routable value into its slug and encoded-identity parts at the last
     * delimiter.
     *
     * @return array{0: string, 1: string|null} `[slug, encodedId]`. `encodedId` is
     *                                          null when the value has no delimiter (a pure-slug candidate) and an
     *                                          empty string when a delimiter is present but no identity follows it.
     */
    public static function split(string $value): array
    {
        $position = strrpos($value, self::DELIMITER);

        if ($position === false) {
            return [$value, null];
        }

        return [substr($value, 0, $position), substr($value, $position + 1)];
    }

    /** Compose a routable value from a slug and an encoded identity: `{slug}_{id}`. */
    public static function compose(string $slug, string $encodedId): string
    {
        return $slug.self::DELIMITER.$encodedId;
    }

    /** Whether the given string is a well-formed slug per {@see self::SLUG_PATTERN}. */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * The locales a record is ANNOUNCED under — the addresses it is served at, not merely the
     * locales it holds slug text for.
     *
     * The two are the same set for most models, and `slugLocales()` is the right answer there.
     * They come apart when one slug is served under several addresses; a model says so by
     * implementing ProvidesAddressLocales, and that contract's docblock has the full reasoning.
     *
     * Resolved HERE rather than in each caller because there are two of them — the URL set on
     * the model, which feeds every hreflang link and `<head>` tag, and the sitemap command —
     * and the whole point of the guarantee is that those two cannot disagree. Two copies of an
     * `instanceof` are two things to keep in step.
     *
     * The fallback is passed IN rather than read off the model, and the parameter is a plain
     * object, because the two callers do not share a type: the sitemap command holds a
     * Sluggable, while HasPolyslug::polyslugUrls() runs on `$this` — and the trait can be used
     * on a class that does not implement Sluggable at all (TraitOnlyModel does exactly that).
     * Typing this Sluggable would be a claim the trait cannot keep. Taking the fallback as an
     * argument keeps the RULE in one place without inventing a type that fits neither caller.
     *
     * LAZY, and that is not a micro-optimization: `slugLocales()` issues a query whenever the
     * slugs relation is not eager-loaded. Taking the list by value would make every model that
     * DOES declare address locales pay for a slug read whose result is then thrown away — one
     * per record, on every render, which is the exact cost this package spent a release
     * removing elsewhere.
     *
     * @param  Closure(): list<string>  $slugLocales  what to announce when the model declares nothing
     * @return list<string>
     */
    public static function announcedLocales(object $model, Closure $slugLocales): array
    {
        return $model instanceof ProvidesAddressLocales
            ? $model->polyslugAddressLocales()
            : $slugLocales();
    }
}
