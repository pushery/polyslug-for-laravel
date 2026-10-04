<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Polyslug\Exceptions\MisconfiguredPolyslug;

/**
 * A `polyslug.*` setting that takes one of a fixed set of words.
 *
 * Each such setting used to be read as an equality against the one value that changes
 * something, so a misspelled value fell back to the default without a word and showed up only in
 * behavior, often weeks later. An unset or null value still means the default; any other value
 * outside the set is refused where it is read.
 *
 * @internal
 */
final class ConfigChoice
{
    /**
     * @param  list<string>  $allowed
     */
    public static function read(string $key, array $allowed, string $default): string
    {
        $value = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.'.$key);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw MisconfiguredPolyslug::unknownConfigValue($key, $value, $allowed);
        }

        return $value;
    }
}
