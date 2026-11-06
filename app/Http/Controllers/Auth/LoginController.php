<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password policy, applied wherever a password is chosen or reset.
 *
 * Minimum 8 characters with at least one letter and one number, and the new
 * password must differ from the current one.
 */
final class PasswordRules
{
    public static function new(): array
    {
        return ['required', 'string', Password::min(8)->letters()->numbers()];
    }
}

class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // Refuse inactive accounts at authentication time as well as on every
        // subsequent request (EnsureUserIsActive middleware).
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $this->audit->loginFailed($request, $credentials['email']);

            // Deliberately vague: do not reveal whether the address exists or
            // whether the account is merely deactivated.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $request->session()->isStarted()) {
            $request->session()->regenerate();
        } else {
            $request->session()->regenerate(true);
        }

        $user = $request->user();

        // Re-hash transparently if the hashing parameters changed.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->login($request, $user);

        // Honour a destination captured before the session was interrupted.
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            $this->audit->logout($request, $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    public function editPassword(): View
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => array_merge(
                PasswordRules::new(),
                ['confirmed', 'different:current_password']
            ),
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // A password change invalidates the old session identifier, which also
        // mitigates session fixation.
        $request->session()->regenerate();

        $this->audit->log('password_changed', "{$user->name} changed their password", $user);

        return redirect()->route('dashboard')
            ->with('status', 'Password updated.');
    }
}