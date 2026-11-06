<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Enrolment') &middot; {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <header class="navbar">
        <div class="navbar-inner">
            <a class="brand" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>

            <button type="button" class="nav-toggle" data-nav-toggle
                    aria-expanded="false" aria-controls="primary-nav">
                Menu
            </button>

            <nav id="primary-nav" class="nav-links" data-nav-panel>
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('programme-summary.index') }}">Programme Summary</a>
                <a href="{{ route('enrolees.index') }}">Enrolees</a>
                @can('viewAny', App\Models\User::class)
                    <a href="{{ route('admin.users.index') }}">Users</a>
                @endcan
                @can('viewAny', App\Models\Program::class)
                    <a href="{{ route('admin.programs.index') }}">Programmes</a>
                @endcan
                @can('export', App\Models\Enrolee::class)
                    <a href="{{ route('admin.exports.index') }}">Export</a>
                @endcan
                @can('viewAny', App\Models\AuditLog::class)
                    <a href="{{ route('admin.audit.index') }}">Audit log</a>
                @endcan
                @can('importLocations', App\Models\Lga::class)
                    <a href="{{ route('admin.locations.index') }}">Locations</a>
                @endcan
            </nav>

            <div class="nav-user">
                <span class="nav-user-name">{{ auth()->user()->name }}</span>
                <span class="nav-user-context">{{ auth()->user()->contextLabel() }}</span>
                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-link">Sign out</button>
                </form>
            </div>
        </div>
    </header>
@endauth

<main class="page">
    @include('partials.flash')

    @yield('content')
</main>

@stack('scripts')
<script src="{{ asset('js/app.js') }}" defer></script>
</body>
</html>