@extends('layouts.app')

@section('title', 'Programme Summary')

@section('content')
    {{-- One screen, three numbers, always the same meaning: how many are
         enrolled in each program, how many this month, how many today. The
         "today" and "this month" figures share the dashboard's window
         definitions, so a card and the KPI row can never disagree. --}}
    <x-ui.page-header
        title="Programme Summary"
        :subtitle="$user->isAdmin()
            ? 'All-time and recent enrolment totals per program.'
            : 'Enrolment totals for ' . $user->program?->code . ', the program your account covers.'"
    />

    <x-ui.filter-bar :action="route('programme-summary.index')" class="programme-filters" aria-label="Filter programme summary">
        @if ($user->isAdmin())
            <x-ui.field label="Program" for="program_id">
                <select id="program_id" name="program_id">
                    <option value="">All programs</option>
                    @foreach (\App\Models\Program::orderBy('code')->get() as $program)
                        <option value="{{ $program->id }}" @selected($filters->programId === $program->id)>
                            {{ $program->code }}
                        </option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif

        <x-ui.field label="LGA" for="lga_id">
            <select id="lga_id" name="lga_id">
                <option value="">All LGAs</option>
                @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}" @selected($filters->lgaId === $lga->id)>{{ $lga->name }}</option>
                @endforeach
            </select>
        </x-ui.field>

        <x-ui.field label="Ward" for="ward_id">
            <select id="ward_id" name="ward_id">
                <option value="">All wards</option>
                @foreach ($wards as $ward)
                    <option value="{{ $ward->id }}" @selected($filters->wardId === $ward->id)>{{ $ward->name }}</option>
                @endforeach
            </select>
        </x-ui.field>

        <x-ui.field label="Created from" for="from">
            <input id="from" type="date" name="from" value="{{ $filters->from }}">
        </x-ui.field>

        <x-ui.field label="Created to" for="to">
            <input id="to" type="date" name="to" value="{{ $filters->to }}">
        </x-ui.field>

        <div class="field filter-wide form-actions">
            <x-ui.button type="submit" variant="primary">Apply</x-ui.button>
            <x-ui.button :href="route('programme-summary.index')" variant="secondary">Reset</x-ui.button>
        </div>
    </x-ui.filter-bar>

    @if ($scopeNote)
        <p class="text-muted text-small programme-scope-note">{{ $scopeNote }}</p>
    @endif

    {{-- Totals above the cards, so a glance answers "how big is this", and the
         cards themselves answer "where does it live". The failed case is an
         honest card rather than a silently empty page of zeros. --}}
    @if ($failed)
        <x-ui.card>
            <x-ui.empty-state title="The totals could not be loaded.">
                <p>There was a problem calculating enrolment figures. Please try again shortly.</p>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif ($cards->isEmpty())
        <x-ui.card>
            <x-ui.empty-state title="No programs are available for your account.">
                <p>Ask an administrator to assign you a program before using the summary.</p>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="programme-grid">
            @foreach ($cards as $card)
                <div class="card programme-card">
                    <div class="programme-card-head">
                        <div>
                            <h2 class="card-title">
                                {{ $card['program']->label() }}
                                @unless ($card['program']->is_active)
                                    <x-ui.badge variant="inactive">Deactivated</x-ui.badge>
                                @endunless
                            </h2>
                            <p class="text-muted text-small">{{ $card['program']->code }}</p>
                        </div>
                        <x-ui.button
                            :href="route('programme-summary.show', array_merge(['program' => $card['program']->id], $dateParams))"
                            variant="secondary"
                            size="sm"
                        >
                            View detail<span class="visually-hidden"> for {{ $card['program']->code }}</span>
                        </x-ui.button>
                    </div>
                    <div class="stat-row stat-row-3">
                        <x-ui.stat :value="number_format($card['total'])" label="Total" />
                        <x-ui.stat :value="number_format($card['month'])" label="This month" />
                        <x-ui.stat :value="number_format($card['today'])" label="Today" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
