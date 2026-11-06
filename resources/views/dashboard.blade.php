@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page-header">
        <h1>Welcome, {{ $user->name }}</h1>
        <p class="page-subtitle">{{ $user->contextLabel() }}</p>
    </div>

    {{-- The three questions, in the order they get asked: how many, how many
         recently, and then what to do about it. No counts by status, per program
         or per account: the enrolee list already answers those, and a number
         here that the list cannot corroborate is a number nobody should trust. --}}
    <div class="stat-row stat-row-3">
        <div class="stat">
            <div class="stat-value">{{ $totals['total'] }}</div>
            <div class="stat-label">Total enrolees</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $totals['month'] }}</div>
            <div class="stat-label">Enrolled this month</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $totals['today'] }}</div>
            <div class="stat-label">Enrolled today</div>
        </div>
    </div>

    <div class="page-header">
        @can('create', App\Models\Enrolee::class)
            <a href="{{ route('enrolees.create') }}" class="btn btn-primary btn-lg">
                Register new enrolee
            </a>
        @endcan

        @can('viewAny', App\Models\Enrolee::class)
            <a href="{{ route('enrolees.index') }}" class="btn btn-primary">
                Browse enrolees
            </a>
        @endcan

        <a href="{{ route('password.edit') }}" class="btn btn-secondary">
            Change password
        </a>

        @cannot('create', App\Models\Enrolee::class)
            {{-- Said once, rather than leaving the absence to be read as an
                 oversight or a broken link. --}}
            <p class="text-muted text-small">
                Your account is read-only, so you cannot register new enrolees.
            </p>
        @endcannot
    </div>

    <div class="card">
        <h2 class="card-title">Recent enrolees</h2>

        @if ($recent->isEmpty())
            <p class="text-muted">No enrolees yet.</p>
        @else
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Name</th>
                            @if ($user->isAdmin())
                                {{-- Only worth a column for an administrator,
                                     whose totals span every program. For anyone
                                     else it is the one program they already know
                                     they are in, printed on every row. --}}
                                <th>Program</th>
                            @endif
                            <th>Enrolled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $enrolee)
                            <tr>
                                <td>
                                    {{-- A record can have no name yet: created, then
                                         left before anything was typed. It is still
                                         an enrolment, so it is listed. --}}
                                    {{ $enrolee->full_name !== '' ? $enrolee->full_name : 'Unnamed enrolee' }}
                                </td>
                                @if ($user->isAdmin())
                                    <td>{{ $enrolee->program?->label() ?? '—' }}</td>
                                @endif
                                <td class="text-muted nowrap">
                                    {{ $enrolee->created_at->diffForHumans() }}
                                </td>
                                <td>
                                    @can('view', $enrolee)
                                        <a href="{{ route('enrolees.show', $enrolee) }}">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Not a metric: the answer to "why do these numbers stop where they do".
         The scope is the reason a Program User sees three and an administrator
         sees every program, so it belongs next to them. --}}
    <div class="card">
        <h2 class="card-title">Your access</h2>
        <dl class="definition-list">
            <dt>Role</dt>
            <dd>{{ $user->role->label() }}</dd>

            <dt>Program</dt>
            <dd>{{ $user->program?->code ?? 'All programs' }}</dd>

            <dt>Last sign-in</dt>
            <dd>{{ $user->last_login_at?->format('j M Y, H:i') ?? '—' }}</dd>
        </dl>
    </div>
@endsection