<?php

declare(strict_types=1);

namespace Polyslug\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stable short link for a sluggable model in one locale. /go/{token} 301s to the
 * model's CURRENT canonical URL, so the short link survives slug renames.
 *
 * @property int $id
 * @property string $token
 * @property string $sluggable_type
 * @property string $sluggable_id
 * @property string $locale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PolyslugShortLink extends Model
{
    protected $table = 'polyslug_short_links';

    /** @var list<string> */
    protected $fillable = [
        'token',
        'sluggable_type',
        'sluggable_id',
        'locale',
    ];
}
