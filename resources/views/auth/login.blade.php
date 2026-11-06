<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="body-auth">
<main class="auth-wrap">
    <div class="card auth-card">
        <h1 class="auth-title">{{ config('app.name') }}</h1>
        <p class="auth-subtitle">Enrolment registration</p>

        @include('partials.flash')

        <form method="POST" action="{{ route('login') }}" data-disable-on-submit>
            @csrf

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username" inputmode="email">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required
                       autocomplete="current-password">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field field-inline">
                <input id="remember" type="checkbox" name="remember" value="1">
                <label for="remember">Keep me signed in on this device</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <span data-submit-label>Sign in</span>
            </button>
        </form>
    </div>
</main>
</body>
</html>