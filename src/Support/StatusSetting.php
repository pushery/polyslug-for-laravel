<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The four `polyslug.*` status settings, and the kind of status each one takes.
 *
 * A redirect takes 301, 302, 303, 307 or 308, and an error answer a client error from 400 to
 * 499. A value of the other kind is not obeyed: a redirect built with 200 is refused by the
 * response itself, so every stale address would end in a 500, and a withdrawn page answered with
 * 200 reads as a live one. Such a value, like one that is no integer at all, gives the default,
 * and `polyslug:doctor` names it, so the default is not taken in silence.
 *
 * @internal
 */
final class StatusSetting
{
    /**
     * Every status setting, with its kind and its default.
     *
     * @var list<array{key: string, redirect: bool, default: int}>
     */
    public const array ALL = [
        ['key' => 'polyslug.redirect.status', 'redirect' => true, 'default' => 301],
        ['key' => 'polyslug.gone.redirect_status', 'redirect' => true, 'default' => 301],
        ['key' => 'polyslug.gone.status', 'redirect' => false, 'default' => 410],
        ['key' => 'polyslug.retired.status', 'redirect' => false, 'default' => 410],
    ];

    /**
     * The statuses a redirect may carry.
     *
     * @var list<int>
     */
    public const array REDIRECTS = [301, 302, 303, 307, 308];

    /**
     * The redirect status configured under $key, or $default when it is not one.
     */
    public static function redirect(string $key, int $default): int
    {
        $status = Container::getInstance()->make(ConfigRepository::class)->get($key);

        return self::isRedirect($status) ? $status : $default;
    }

    /**
     * The client error status configured under $key, or $default when it is not one.
     */
    public static function error(string $key, int $default): int
    {
        $status = Container::getInstance()->make(ConfigRepository::class)->get($key);

        return self::isError($status) ? $status : $default;
    }

    /**
     * @phpstan-assert-if-true int $status
     */
    public static function isRedirect(mixed $status): bool
    {
        return is_int($status) && in_array($status, self::REDIRECTS, true);
    }

    /**
     * @phpstan-assert-if-true int $status
     */
    public static function isError(mixed $status): bool
    {
        return is_int($status) && $status >= 400 && $status <= 499;
    }
}
