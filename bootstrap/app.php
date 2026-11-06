<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\IdleSessionTimeout;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        // Replaces the framework's own TrimStrings so the NIN can be excluded:
        // its rule is a literal `^\d{11}$`, and trimming first would quietly
        // let "12345678901 " through. Everything else is trimmed as usual.
        $middleware->replace(\Illuminate\Foundation\Http\Middleware\TrimStrings::class, TrimStrings::class);

        // Security headers on every response, including error pages.
        $middleware->append(SecurityHeaders::class);

        // Deactivated users and idle sessions are enforced on every
        // authenticated request. Order matters: deactivation is checked first
        // so an inactive user cannot keep refreshing a session.
        $middleware->web(append: [
            IdleSessionTimeout::class,
            EnsureUserIsActive::class,
        ]);

        // Redirect authenticated users away from the login screen.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();