<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Discards the session after a period of inactivity.
 *
 * Session::lifetime governs absolute cookie lifetime, but Laravel will keep a
 * session alive indefinitely as long as requests keep arriving. This middleware
 * adds the "idle" half of the requirement: if no request has been made for
 * SESSION_IDLE_MINUTES, the user is logged out.
 *
 * Guests are left alone so that an abandoned login form does not rotate CSRF
 * tokens for the next visitor.
 */
class IdleSessionTimeout
{
    public const LAST_ACTIVITY_KEY = '_last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $limit = (int) config('session.idle_minutes', 30);

        if ($limit > 0) {
            $now = time();
            $last = $request->session()->get(self::LAST_ACTIVITY_KEY, $now);

            if ($now - (int) $last > $limit * 60) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['email' => 'You were signed out after a period of inactivity.']);
            }
        }

        $request->session()->put(self::LAST_ACTIVITY_KEY, time());

        return $next($request);
    }
}