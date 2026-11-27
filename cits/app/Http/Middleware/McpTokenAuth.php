<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class McpTokenAuth
{
    private const MAX_PER_MINUTE = 60;

    /** Failed bearer tokens per client IP per minute. */
    private const MAX_FAILURES_PER_MINUTE = 20;

    public function __construct(private ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failKey = 'mcp-fail:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failKey, self::MAX_FAILURES_PER_MINUTE)) {
            return response()->json(['message' => '驗證失敗次數過多，請稍後再試。'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($failKey));
        }
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : $this->tokens->authenticate($plain);

        if (! $token) {
            RateLimiter::hit($failKey, 60);

            return response()->json(['message' => '未授權：權杖無效、已撤銷或已過期。'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
        }

        $key = 'mcp-token:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            return response()->json(['message' => '請求過於頻繁，請稍後再試。'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        $this->tokens->touch($token);
        // Stateless: no session is written, user lives for this request only.
        Auth::guard('web')->setUser($token->user);
        Auth::shouldUse('web');

        return $next($request);
    }
}
