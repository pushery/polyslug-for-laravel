<?php

declare(strict_types=1);

namespace Polyslug;

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
}
