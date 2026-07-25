<?php

declare(strict_types=1);

namespace Polyslug\Encoders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use Polyslug\Contracts\IdentityEncoder;

/**
 * Leak-safe identity: each key maps to an unguessable random token kept in the
 * polyslug_tokens table. The URL token reveals nothing about the key — no row count,
 * order, or value — so it suits enumeration-sensitive, integer-keyed tables. The
 * token is stable per key (one row), so each record keeps a single canonical URL.
 */
final class RandomTokenEncoder implements IdentityEncoder
{
    /** @var array<string, string> */
    private array $encoded = [];

    /** @var array<string, int|string|null> */
    private array $decoded = [];

    #[Override]
    public function encode(int|string $id): string
    {
        $key = (string) $id;

        if (isset($this->encoded[$key])) {
            return $this->encoded[$key];
        }

        $existing = DB::table('polyslug_tokens')->where('key_value', $key)->value('token');

        if (is_string($existing)) {
            return $this->encoded[$key] = $existing;
        }

        $token = Str::lower(Str::random(16));

        DB::table('polyslug_tokens')->insert([
            'key_value' => $key,
            'token' => $token,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $this->encoded[$key] = $token;
    }

    #[Override]
    public function decode(string $token): int|string|null
    {
        if (array_key_exists($token, $this->decoded)) {
            return $this->decoded[$token];
        }

        $key = DB::table('polyslug_tokens')->where('token', $token)->value('key_value');

        return $this->decoded[$token] = is_string($key) ? $key : null;
    }
}
