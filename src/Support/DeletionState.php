<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Whether a model is being permanently removed. Lives outside the trait so the
 * SoftDeletes / isForceDeleting() detection is analyzed generically (with $model typed
 * as Model), rather than "in context of" every using class — which trips method_exists
 * narrowing for classes that do not use SoftDeletes.
 */
final class DeletionState
{
    public static function isForceDeleting(Model $model): bool
    {
        // A model without SoftDeletes has no soft state: delete() is always permanent.
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return true;
        }

        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
    }
}
