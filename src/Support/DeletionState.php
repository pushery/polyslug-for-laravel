<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Database\Eloquent\Model;

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
        //
        // The framework's own check rather than our copy of it. Model::isSoftDeletable()
        // exists at the declared illuminate/database floor and memoizes per class in a
        // static, so a bulk delete stops re-running class_uses_recursive() once per row —
        // and the rule for what counts as soft-deletable stops being maintained in two
        // places, one of which is this package.
        if (! $model::isSoftDeletable()) {
            return true;
        }

        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
    }
}
