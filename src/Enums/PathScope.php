<?php

declare(strict_types=1);

namespace Polyslug\Enums;

/**
 * A value a scope column takes from the URL rather than from the caller.
 *
 * `polyslugResolutionScope()` names the scope a caller knows. A model nested under records of its
 * own class carries its parent in the scope as well, and the caller does not know that value: the
 * ancestor part of the path names it. `PathScope::Parent` hands the column to the path. The
 * resolution resolves the ancestor part through the model's own resolution and uses the key of the
 * record it names, and a path without an ancestor part names no parent.
 */
enum PathScope
{
    case Parent;
}
