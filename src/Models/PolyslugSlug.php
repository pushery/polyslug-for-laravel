<?php

declare(strict_types=1);

namespace Polyslug\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Override;

/**
 * One slug for a sluggable model in one locale and uniqueness scope. At most one row
 * per (sluggable_type, locale, scope, case-insensitive slug) may be current and not
 * deleted; superseded slugs stay as history (is_current = false) so old URLs can 301.
 *
 * @property int $id
 * @property string $sluggable_type
 * @property string $sluggable_id
 * @property string $locale
 * @property string $scope
 * @property string $slug
 * @property bool $is_current
 * @property bool $enforce_unique
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $sluggable
 */
final class PolyslugSlug extends Model
{
    use SoftDeletes;

    protected $table = 'polyslug_slugs';

    /** @var list<string> */
    protected $fillable = [
        'sluggable_type',
        'sluggable_id',
        'locale',
        'scope',
        'slug',
        'is_current',
        'enforce_unique',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function sluggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'enforce_unique' => 'boolean',
        ];
    }
}
