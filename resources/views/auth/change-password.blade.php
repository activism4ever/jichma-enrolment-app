<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password &middot; {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<main class="page page-narrow">
    <div class="card">
        <h1>Change your password</h1>

        @if (auth()->user()->must_change_password)
            <div class="alert alert-info">
                You must set a new password before you can use the application.
            </div>
        @endif

        @include('partials.flash')

        <form method="POST" action="{{ route('password.update') }}" data-disable-on-submit>
            @csrf
            @method('PUT')

            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password"
                       required autocomplete="current-password" autofocus>
                @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required
                       autocomplete="new-password" minlength="8">
                <p class="field-hint">At least 8 characters, including a letter and a number.</p>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       required autocomplete="new-password" minlength="8">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <span data-submit-label>Update password</span>
                </button>
                @unless (auth()->user()->must_change_password)
                    <a href="{{ route('dashboard') }}" class="btn btn-link">Cancel</a>
                @endunless
            </div>
        </form>
    </div>
</main>
</body>
</html>