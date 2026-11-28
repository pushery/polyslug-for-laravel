<?php

declare(strict_types=1);

namespace Polyslug\Support;

use InvalidArgumentException;

/**
 * Reads a switch from the value Laravel's env helper gives for it.
 *
 * The rule is a method and not an expression in config/, because a host that publishes the config
 * keeps the file as it was on that day. A rule written into the file never takes a later fix; a
 * call of this method takes it with the next update of the package.
 *
 * The env helper turns `true`, `false`, `(true)` and `(false)` into booleans, `empty` and `(empty)`
 * into an empty string and `null` and `(null)` into null, in any case. Every other spelling stays a
 * string, so the spellings that switch something off and on are named here, trimmed and in any case.
 * A missing line keeps the value the package ships, and so does an empty one, as copying a template
 * of a .env leaves it, and so do the four spellings the helper has already turned into an empty
 * string or null. A spelling that names neither is refused with the name of the variable: the host
 * wrote a value and meant one of the two, and reading it as the shipped value would leave a switch
 * the host meant to turn on, a hardening switch shipped off among them, as it was.
 *
 * Part of the public API, because the configuration file this package publishes calls it. Once
 * published, the file runs in the host's own namespace, and a host that analyzes its configuration
 * reads a call there into a class the package marks internal as an error it cannot fix without
 * editing a published file.
 */
final class EnvSwitch
{
    /** @var list<string> */
    private const array OFF = ['0', 'false', 'no', 'off'];

    /** @var list<string> */
    private const array ON = ['1', 'true', 'yes', 'on'];

    /**
     * @param  mixed  $value  what the env helper returned for the switch
     * @param  bool  $default  the value the package ships, kept for a missing or an empty line
     * @param  string|null  $variable  the name of the variable, for the message that refuses a spelling
     *
     * @throws InvalidArgumentException for a spelling that names neither off nor on
     */
    public static function read(mixed $value, bool $default, ?string $variable = null): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $default;
        }

        $spelling = strtolower(trim($value));

        return match (true) {
            $spelling === '' => $default,
            in_array($spelling, self::OFF, true) => false,
            in_array($spelling, self::ON, true) => true,
            default => throw new InvalidArgumentException(sprintf(
                '%s is "%s", which switches nothing. Write one of %s to switch it on, one of %s to switch it off, or leave it empty for the value the package ships.',
                $variable ?? 'A switch',
                trim($value),
                implode(', ', self::ON),
                implode(', ', self::OFF),
            )),
        };
    }
}
