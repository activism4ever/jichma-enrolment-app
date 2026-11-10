@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- The page opens with who is signed in and what they may do. The actions
         live in the header so the first screenful is one question, not a
         toolbar: how many people are enrolled, and what to do next. --}}
    <x-ui.page-header
        :title="'Welcome, ' . $user->name"
        :subtitle="$user->contextLabel()"
    >
        <x-slot:actions>
            @can('create', App\Models\Enrolee::class)
                <x-ui.button :href="route('enrolees.create')" variant="primary" size="lg">
                    Register new enrolee
                </x-ui.button>
            @endcan

            @can('viewAny', App\Models\Enrolee::class)
                <x-ui.button :href="route('enrolees.index')" variant="primary">
                    Browse enrolees
                </x-ui.button>
            @endcan

            <x-ui.button :href="route('password.edit')" variant="secondary">
                Change password
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @cannot('create', App\Models\Enrolee::class)
        {{-- Said once, rather than leaving the absence to be read as an
             oversight or a broken link. --}}
        <p class="text-muted text-small dashboard-readonly-note">
            Your account is read-only, so you cannot register new enrolees.
        </p>
    @endcannot

    {{-- The three questions, in the order they get asked: how many, how many
         recently, and then what to do about it. No counts by status, per program
         or per account: the enrolee list already answers those, and a number
         here that the list cannot corroborate is a number nobody should trust. --}}
    <section class="dashboard-overview" aria-labelledby="dashboard-overview-heading">
        <h2 id="dashboard-overview-heading" class="visually-hidden">Enrolment overview</h2>

        <div class="stat-row stat-row-3">
            <x-ui.stat :value="$totals['total']" label="Total enrolees" />
            <x-ui.stat :value="$totals['month']" label="Enrolled this month" />
            <x-ui.stat :value="$totals['today']" label="Enrolled today" />
        </div>
    </section>

    <x-ui.card title="Recent enrolees">
        @if ($recent->isEmpty())
            <x-ui.empty-state title="No enrolees yet.">
                <p>Recently enrolled people will appear here as they are registered.</p>
            </x-ui.empty-state>
        @else
            <x-ui.table class="dashboard-table" caption="Most recent enrolments">
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
                            <td data-label="Name">
                                {{-- A record can have no name yet: created, then
                                     left before anything was typed. It is still
                                     an enrolment, so it is listed. --}}
                                {{ $enrolee->full_name !== '' ? $enrolee->full_name : 'Unnamed enrolee' }}
                            </td>
                            @if ($user->isAdmin())
                                <td data-label="Program">{{ $enrolee->program?->label() ?? '—' }}</td>
                            @endif
                            <td data-label="Enrolled" class="text-muted nowrap">
                                {{ $enrolee->created_at->diffForHumans() }}
                            </td>
                            <td data-label="Actions">
                                @can('view', $enrolee)
                                    <a href="{{ route('enrolees.show', $enrolee) }}">
                                        View<span class="visually-hidden"> {{ $enrolee->full_name !== '' ? $enrolee->full_name : 'unnamed enrolee' }}</span>
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- Not a metric: the answer to "why do these numbers stop where they do".
         The scope is the reason a Program User sees three and an administrator
         sees every program, so it belongs next to them. --}}
    <x-ui.card title="Your access">
        <dl class="definition-list">
            <dt>Role</dt>
            <dd>{{ $user->role->label() }}</dd>

            <dt>Program</dt>
            <dd>{{ $user->program?->code ?? 'All programs' }}</dd>

            <dt>Last sign-in</dt>
            <dd>{{ $user->last_login_at?->format('j M Y, H:i') ?? '—' }}</dd>
        </dl>
    </x-ui.card>
@endsection
