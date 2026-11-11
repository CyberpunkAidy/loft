<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response the app sends, error pages and redirects included
 * (decision 42). A response that sets one of these itself keeps its own: studio logos send
 * a stricter Content-Security-Policy.
 */
class SecurityHeaders
{
    public const HEADERS = [
        // Only Loft's own pages may put a Loft page in a frame, so another site can't lay one
        // under its own and trick a click. X-Frame-Options is for browsers without frame-ancestors.
        // No <base> tags and no plugins (<object>, <embed>), so nothing injected can use them.
        'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
        'X-Frame-Options' => 'SAMEORIGIN',
        // Files are only what their Content-Type says, never guessed into a script or page.
        'X-Content-Type-Options' => 'nosniff',
        // Other sites see only https://loftstudio.app, never a page's path or query (signed links).
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        // Loft never asks for these; no script on its pages can either.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /** A year, for every subdomain. Only sent over HTTPS: browsers ignore it on HTTP. */
    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
