<?php

declare(strict_types=1);

namespace Polyslug\Support;

use InvalidArgumentException;

/**
 * The `locale` column of the slug and short-link tables, and the check every write makes against it.
 *
 * Both tables declare the column as `string('locale', 16)`. A longer locale is refused by
 * PostgreSQL, stored whole by SQLite, and cut short by MySQL, because the write paths insert with
 * `INSERT IGNORE`, which turns the length error into a warning. The row then carries a different
 * locale than the one it was written for: no read under the full locale finds it, and a second
 * locale sharing the first 16 characters is refused as if another writer had claimed the row. So
 * a write refuses such a locale before it touches a table, on every engine alike.
 */
final class LocaleColumn
{
    public const int LENGTH = 16;

    /**
     * The locale, when the column can hold it.
     *
     * @throws InvalidArgumentException when the locale is longer than the column
     */
    public static function storable(string $locale): string
    {
        $length = mb_strlen($locale);

        if ($length > self::LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The locale [%s] has %d characters, and the polyslug tables store a locale of at most %d. '
                .'Use a shorter locale code for slugs and short links.',
                $locale,
                $length,
                self::LENGTH,
            ));
        }

        return $locale;
    }
}
