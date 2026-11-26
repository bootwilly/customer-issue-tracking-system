<?php

namespace App\Http\Middleware;

use App\Services\AuthLogService;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the user out after `session.idle_minutes` (system setting, 0 = off) without a request.
 * Requests sent with the header X-CITS-Background (edit-lock heartbeat and similar timers) do not count as activity.
 * A "remember me" login is refused while the limit is on, so the cookie cannot bring an idle user back.
 */
class LogoutWhenIdle
{
    public const SESSION_KEY = 'idle.last_at';

    public const BACKGROUND_HEADER = 'X-CITS-Background';

    public function __construct(private AuthService $auth, private AuthLogService $authLog) {}

    public function handle(Request $request, Closure $next): Response
    {
        $minutes = $this->auth->idleMinutes();
        if ($minutes <= 0 || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $last = (int) $session->get(self::SESSION_KEY, 0);
        $viaRemember = Auth::viaRemember();
        $expired = $viaRemember || ($last > 0 && now()->getTimestamp() - $last > $minutes * 60);

        if ($expired) {
            $this->authLog->record($request, AuthLogService::IDLE_LOGOUT, Auth::user(), null, $viaRemember ? 'remember' : null);
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();
            $message = "閒置超過 {$minutes} 分鐘，已自動登出，請重新登入。";

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 401)
                : redirect()->route('home')->with('err', $message);
        }

        if ($last === 0 || ! $request->headers->has(self::BACKGROUND_HEADER)) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());
        }

        return $next($request);
    }
}
