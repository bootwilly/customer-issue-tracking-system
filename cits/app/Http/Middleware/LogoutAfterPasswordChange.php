<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a session out when the account's password changed after the session last saw it (changed on another
 * device, or reset through the e-mail link). The session keeps [user id, HMAC of the password hash]; the hash
 * itself is never stored. A request that changes the password refreshes its own entry on the way out, so the
 * device that made the change stays signed in. A different user id in the entry (sign-in as someone else) only
 * starts a new entry.
 */
class LogoutAfterPasswordChange
{
    public const SESSION_KEY = 'auth.pwd_fp';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $seen = $request->session()->get(self::SESSION_KEY);
            if (is_array($seen) && ($seen[0] ?? null) === $user->getAuthIdentifier() && ! hash_equals((string) ($seen[1] ?? ''), $this->fingerprint($user->getAuthPassword()))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $msg = '密碼已變更，請重新登入。';

                return $request->expectsJson()
                    ? response()->json(['message' => $msg], 401)
                    : redirect()->route('home')->with('err', $msg);
            }
        }

        $response = $next($request);

        $after = $request->user();
        if ($after && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, [$after->getAuthIdentifier(), $this->fingerprint($after->getAuthPassword())]);
        }

        return $response;
    }

    private function fingerprint(?string $passwordHash): string
    {
        return hash_hmac('sha256', (string) $passwordHash, (string) config('app.key'));
    }
}
