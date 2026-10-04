<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Database\Eloquent\Model;
use Polyslug\Contracts\Sluggable;

/**
 * Where a superseded record sends its visitor, answered once for every caller.
 *
 * The canonical middleware redirects a superseded record to the answer, and the sitemap leaves
 * out exactly the records the middleware would redirect. Both ask this class, so the two cannot
 * disagree about which records are served at their own address.
 */
final class SuccessorChain
{
    /**
     * How many successors one answer follows. A longer chain ends at the last link reached, and
     * that address carries the visitor on.
     */
    public const int HOPS = 8;

    /**
     * The end of the chain of successors that starts at $value, as far as the requester may see
     * it, or null when there is nowhere to send them.
     *
     * Each link is resolved rather than merely checked, so the answer is the row the resolution
     * gate returned and not the instance the model handed over, and a link the requester may not
     * see ends the chain at the one before it. A chain that comes back to a record it already
     * passed would redirect between those addresses forever. Such a ring, a record that names
     * itself included, is no successor at all, and the record is served as one nobody superseded.
     */
    public static function lastVisible(Sluggable $value): ?Sluggable
    {
        $passed = [$value];
        $current = $value;

        for ($hop = 0; $hop < self::HOPS; $hop++) {
            $successor = $current->polyslugSupersededBy();
            $visible = $successor instanceof Sluggable ? $successor->polyslugResolveSelf() : null;

            if (! $visible instanceof Sluggable) {
                break;
            }

            foreach ($passed as $earlier) {
                if ($visible === $earlier || ($visible instanceof Model && $earlier instanceof Model && $visible->is($earlier))) {
                    return null;
                }
            }

            $passed[] = $visible;
            $current = $visible;
        }

        return $current === $value ? null : $current;
    }
}
