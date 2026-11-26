<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defence in depth for every web response. Pages use inline scripts and Alpine (which needs eval),
 * so script-src is not restricted here; the CSP blocks plugins, <base> hijacking, framing by other sites and
 * forms posting to other origins. Responses that already set their own CSP (attachments, flow previews) keep it.
 */
class SecurityHeaders
{
    private const CSP = "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->has('Content-Security-Policy') || $h->set('Content-Security-Policy', self::CSP);
        $h->has('X-Content-Type-Options') || $h->set('X-Content-Type-Options', 'nosniff');
        $h->has('X-Frame-Options') || $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->has('Referrer-Policy') || $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
