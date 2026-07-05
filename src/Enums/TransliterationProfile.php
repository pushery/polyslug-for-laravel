<?php

declare(strict_types=1);

namespace Polyslug\Enums;

/**
 * How accented and non-ASCII characters are folded when building a slug.
 */
enum TransliterationProfile: string
{
    /** ASCII fold: ü→u, ö→o, ä→a, ß→ss. */
    case Simple = 'simple';

    /** DIN 5007-2 (German): ü→ue, ö→oe, ä→ae, ß→ss. */
    case Din = 'din';

    /** The Str::slug language whose transliteration table this profile uses. */
    public function language(): string
    {
        return match ($this) {
            self::Simple => 'en',
            self::Din => 'de',
        };
    }
}
