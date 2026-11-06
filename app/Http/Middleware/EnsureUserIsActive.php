<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated users immediately, including sessions that were already
 * established before the deactivation.
 *
 * The login controller already refuses an inactive account, but that check runs
 * once. Without this middleware a user deactivated mid-session would keep
 * working until their session expired.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Re-read the flag rather than trusting the value serialised into
            // the session, which may be stale by hours.
            if (! $user->fresh()?->is_active) {
                auth()->guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['email' => 'This account has been deactivated.']);
            }
        }

        return $next($request);
    }
}