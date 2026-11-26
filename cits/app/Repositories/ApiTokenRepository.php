<?php

namespace App\Repositories;

use App\Models\ApiToken;
use Illuminate\Support\Collection;

class ApiTokenRepository
{
    public function create(array $data): ApiToken
    {
        return ApiToken::create($data);
    }

    public function findByHash(string $hash): ?ApiToken
    {
        return ApiToken::with('user')->where('token_hash', $hash)->first();
    }

    /** @return Collection<int, ApiToken> */
    public function forUser(int $userId): Collection
    {
        return ApiToken::where('user_id', $userId)->orderByDesc('id')->get();
    }

    public function findOwned(int $id, int $userId): ?ApiToken
    {
        return ApiToken::where('id', $id)->where('user_id', $userId)->first();
    }

    public function revoke(ApiToken $t): void
    {
        $t->forceFill(['revoked_at' => now()])->save();
    }

    public function touchLastUsed(ApiToken $t): void
    {
        $t->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
