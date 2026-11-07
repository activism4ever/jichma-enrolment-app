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
<a class="skip-link" href="#main-content">Skip to main content</a>

@auth
    <header class="navbar">
        <div class="navbar-inner">
            <a class="brand" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>

            <button type="button" class="nav-toggle" data-nav-toggle
                    aria-expanded="false" aria-controls="primary-nav">
                Menu
            </button>

            <nav id="primary-nav" class="nav-links" data-nav-panel aria-label="Primary">
                <x-ui.nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    Dashboard
                </x-ui.nav-link>

                <x-ui.nav-link :href="route('programme-summary.index')" :active="request()->routeIs('programme-summary.*')">
                    Programme Summary
                </x-ui.nav-link>

                <x-ui.nav-link :href="route('enrolees.index')" :active="request()->routeIs('enrolees.*')">
                    Enrolees
                </x-ui.nav-link>

                @can('viewAny', App\Models\User::class)
                    <x-ui.nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                        Users
                    </x-ui.nav-link>
                @endcan

                @can('viewAny', App\Models\Program::class)
                    <x-ui.nav-link :href="route('admin.programs.index')" :active="request()->routeIs('admin.programs.*')">
                        Programmes
                    </x-ui.nav-link>
                @endcan

                @can('export', App\Models\Enrolee::class)
                    <x-ui.nav-link :href="route('admin.exports.index')" :active="request()->routeIs('admin.exports.*')">
                        Export
                    </x-ui.nav-link>
                @endcan

                @can('viewAny', App\Models\AuditLog::class)
                    <x-ui.nav-link :href="route('admin.audit.index')" :active="request()->routeIs('admin.audit.*')">
                        Audit log
                    </x-ui.nav-link>
                @endcan

                @can('importLocations', App\Models\Lga::class)
                    <x-ui.nav-link :href="route('admin.locations.index')" :active="request()->routeIs('admin.locations.*')">
                        Locations
                    </x-ui.nav-link>
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

<main id="main-content" class="page" tabindex="-1">
    @include('partials.flash')

    @yield('content')
</main>

@stack('scripts')
<script src="{{ asset('js/app.js') }}" defer></script>
</body>
</html>
