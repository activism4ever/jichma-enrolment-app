<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forcibly routes a user who still holds the seeded or admin-issued temporary
 * password to the change-password screen before they can use the application.
 *
 * JSON and other non-HTML requests get a 403 instead of a redirect, so an API
 * or XHR caller never receives an HTML login page where it expected data.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('password.*', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Password change required.');
        }

        return redirect()->route('password.edit');
    }
}