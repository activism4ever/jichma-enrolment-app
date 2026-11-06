<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers.
 *
 * The Content-Security-Policy is deliberately strict: the application ships one
 * stylesheet and one script file, both self-hosted, so no third-party origin and
 * no inline script is needed. That is why the front end uses plain vanilla JS
 * rather than Alpine.js, which would require 'unsafe-eval' to evaluate
 * expressions.
 *
 * 'unsafe-inline' is granted to styles only, because inline style attributes are
 * used for the photo preview and progress bars. Scripts get no such exemption.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
            // 'none' would block the page entirely; the legacy value is
            // required by some older browsers that ignore frame-ancestors.
            'X-XSS-Protection' => '0',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
        }

        if ($request->isMethod('GET') && ! $response->headers->has('Strict-Transport-Security')) {
            // 6 months, subdomains. Sent only over HTTPS; harmless otherwise
            // because browsers ignore it on plain HTTP.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=15552000; includeSubDomains'
            );
        }

        return $response;
    }
}