<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\ApiToken;
use App\Models\User;
use App\Repositories\ApiTokenRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ApiTokenService
{
    public const PREFIX = 'cits_';

    public const EXPIRY_CHOICES = ['30', '90', '365', 'none'];

    /** Minimum seconds between last_used_at writes. */
    private const TOUCH_INTERVAL = 60;

    public function __construct(private ApiTokenRepository $repo) {}

    public function assertAllowed(User $user): void
    {
        if (! $this->allowed($user)) {
            throw new BusinessException('客戶帳號不可使用 API 權杖。');
        }
    }

    public function allowed(User $user): bool
    {
        return $user->isActive() && ($user->is_admin || ! $user->isClient());
    }

    /** @return array{token: ApiToken, plain: string} plain is shown once, only its hash is stored */
    public function create(User $user, string $name, string $expiry): array
    {
        $this->assertAllowed($user);
        $plain = self::PREFIX.Str::random(40);
        $token = $this->repo->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => $this->hash($plain),
            'expires_at' => ctype_digit($expiry) ? now()->addDays((int) $expiry) : null,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    /** @return Collection<int, ApiToken> */
    public function listFor(User $user): Collection
    {
        return $this->repo->forUser($user->id);
    }

    public function revoke(User $user, int $id): void
    {
        $t = $this->repo->findOwned($id, $user->id) ?? throw new BusinessException('找不到權杖。');
        if ($t->revoked_at === null) {
            $this->repo->revoke($t);
        }
    }

    /** Resolve a plain bearer token to its (valid) token row, or null. */
    public function authenticate(string $plain): ?ApiToken
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $t = $this->repo->findByHash($this->hash($plain));
        if (! $t || $t->revoked_at !== null || ($t->expires_at !== null && $t->expires_at->isPast())) {
            return null;
        }
        if (! $t->user || ! $this->allowed($t->user)) {
            return null;
        }

        return $t;
    }

    public function touch(ApiToken $t): void
    {
        if ($t->last_used_at === null || $t->last_used_at->diffInSeconds(now(), true) >= self::TOUCH_INTERVAL) {
            $this->repo->touchLastUsed($t);
        }
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
