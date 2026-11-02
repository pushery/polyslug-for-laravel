<?php

declare(strict_types=1);

namespace Polyslug\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Polyslug\Models\PolyslugShortLink;
use Polyslug\Models\PolyslugSlug;

/**
 * Forgets what Polyslug holds for records that are gone for good, many keys at a time.
 *
 * A model's own delete reaches HasPolyslug::polyslugOnDeleted() through its `deleted` event. A
 * delete that runs as a query fires none, so the slug rows of those records stay behind, with text
 * taken from their titles, and so do their short links and identity tokens. This is what such a
 * delete calls with the keys it removes, through HasPolyslug::polyslugForget().
 *
 * The slug rows are deleted, all of them: the current slugs, the history and the released rows,
 * as a hard delete of the model deletes them.
 *
 * The short links and identity tokens are not deleted. Under the sequential scheme both stores
 * count from the rows that exist, so a deleted row frees its token for the next record that asks,
 * and every URL and printed code that carries the token then leads to that record. Each row moves
 * to the forgotten type instead: it keeps its token, which the unique index then refuses to every
 * later record, and its key is replaced by that token, so the row no longer names the record.
 * Nothing resolves a forgotten token, and its URL answers 404 like the URL of any deleted record.
 *
 * Tokens in the untyped lane stay. Such a row was issued before tokens had owners, and its key can
 * be the key of a record of any type: TokenStore hands it to the first type that asks for that
 * key. Forgetting it for one type could take a published URL from a record of another type that
 * still exists.
 *
 * Running it again is safe, and so is running it before or after the delete it goes with: it
 * changes only rows that still belong to the given type and keys. It opens no transaction, because
 * the slug rows, the short links and the tokens can sit on three connections.
 */
final class ForgottenRecords
{
    /**
     * The type a forgotten short link or identity token is moved to.
     *
     * Neither a class name nor the untyped lane, so nothing reads it as an owner: TokenStore reads
     * the lane of the type it serves and the untyped lane, and the short-link route finds no model
     * for this type and answers 404.
     */
    public const string TYPE = '#forgotten';

    /**
     * How many keys one statement binds, one parameter each: far below the 32,766 parameters
     * SQLite accepts and the 65,535 of PostgreSQL and MySQL, however many keys a caller passes.
     */
    private const int SLICE = 1_000;

    /**
     * @param  string  $type  the morph type the rows were written under
     * @param  iterable<mixed>  $keys  the records' keys; a value that is not a scalar, or is empty, names no record and is skipped
     * @param  PolyslugSlug  $slugs  a slug model on the connection the type's slug rows are written to
     *
     * @throws InvalidArgumentException when $type is the untyped lane or the forgotten type, which belong to no model
     */
    public static function forget(string $type, iterable $keys, PolyslugSlug $slugs): void
    {
        if ($type === TokenStore::UNTYPED || $type === self::TYPE) {
            throw new InvalidArgumentException(sprintf(
                'Polyslug forgets the records of a model type, and [%s] is none: it holds rows that belong to no model.',
                $type,
            ));
        }

        $wanted = [];

        foreach ($keys as $key) {
            if (is_scalar($key) && (string) $key !== '') {
                $wanted[(string) $key] = true;
            }
        }

        $now = Carbon::now();

        // Cast back to string, for the reason TokenStore::tokensFor() gives: PHP turns a numeric
        // string array key into an int, and every key column is a string column.
        foreach (array_chunk(array_map(strval(...), array_keys($wanted)), self::SLICE) as $slice) {
            // forceDelete() runs the delete without the model's scopes, so a released row, which
            // carries `deleted_at`, is deleted with the others.
            $slugs->newModelQuery()
                ->where('sluggable_type', $type)
                ->whereIn('sluggable_id', $slice)
                ->forceDelete();

            PolyslugShortLink::resolve()->newModelQuery()
                ->where('sluggable_type', $type)
                ->whereIn('sluggable_id', $slice)
                ->update(['sluggable_type' => self::TYPE, 'sluggable_id' => new Expression('token')]);

            DB::table('polyslug_tokens')
                ->where('key_type', $type)
                ->whereIn('key_value', $slice)
                ->update(['key_type' => self::TYPE, 'key_value' => new Expression('token'), 'updated_at' => $now]);
        }
    }
}
