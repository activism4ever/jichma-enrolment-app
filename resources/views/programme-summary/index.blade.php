@extends('layouts.app')

@section('title', 'Programme Summary')

@section('content')
    {{-- One screen, three numbers, always the same meaning: how many are
         enrolled in each program, how many this month, how many today. The
         "today" and "this month" figures share the dashboard's window
         definitions, so a card and the KPI row can never disagree. --}}
    <div class="page-header">
        <h1>Programme Summary</h1>
        <p class="page-subtitle">
            @if ($user->isAdmin())
                All-time and recent enrolment totals per program.
            @else
                Enrolment totals for {{ $user->program?->code }}, the program your account covers.
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('programme-summary.index') }}" class="filters card">
        @if ($user->isAdmin())
            <div class="field">
                <label for="program_id">Program</label>
                <select id="program_id" name="program_id">
                    <option value="">All programs</option>
                    @foreach (\App\Models\Program::orderBy('code')->get() as $program)
                        <option value="{{ $program->id }}" @selected($filters->programId === $program->id)>
                            {{ $program->code }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field">
            <label for="lga_id">LGA</label>
            <select id="lga_id" name="lga_id">
                <option value="">All LGAs</option>
                @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}" @selected($filters->lgaId === $lga->id)>{{ $lga->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="ward_id">Ward</label>
            <select id="ward_id" name="ward_id">
                <option value="">All wards</option>
                @foreach ($wards as $ward)
                    <option value="{{ $ward->id }}" @selected($filters->wardId === $ward->id)>{{ $ward->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="from">Created from</label>
            <input id="from" type="date" name="from" value="{{ $filters->from }}">
        </div>

        <div class="field">
            <label for="to">Created to</label>
            <input id="to" type="date" name="to" value="{{ $filters->to }}">
        </div>

        <div class="field filter-wide form-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('programme-summary.index') }}" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    @if ($scopeNote)
        <p class="text-muted text-small">{{ $scopeNote }}</p>
    @endif

    {{-- Totals above the cards, so a glance answers "how big is this", and the
         cards themselves answer "where does it live". The failed case is an
         honest card rather than a silently empty page of zeros. --}}
    @if ($failed)
        <div class="card">
            <div class="empty-state">
                <p><strong>The totals could not be loaded.</strong></p>
                <p>There was a problem calculating enrolment figures. Please try again shortly.</p>
            </div>
        </div>
    @elseif ($cards->isEmpty())
        <div class="card">
            <div class="empty-state">
                <p><strong>No programs are available for your account.</strong></p>
                <p>Ask an administrator to assign you a program before using the summary.</p>
            </div>
        </div>
    @else
        <div class="programme-grid">
            @foreach ($cards as $card)
                <div class="card programme-card">
                    <div class="programme-card-head">
                        <div>
                            <h2 class="card-title">
                                {{ $card['program']->label() }}
                                @unless ($card['program']->is_active)
                                    <span class="badge badge-inactive">Deactivated</span>
                                @endunless
                            </h2>
                            <p class="text-muted text-small">{{ $card['program']->code }}</p>
                        </div>
                        <a href="{{ route('programme-summary.show', array_merge(['program' => $card['program']->id], $dateParams)) }}"
                           class="btn btn-sm btn-secondary">
                            View detail
                        </a>
                    </div>
                    <div class="stat-row stat-row-3">
                        <div class="stat">
                            <div class="stat-value">{{ number_format($card['total']) }}</div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value">{{ number_format($card['month']) }}</div>
                            <div class="stat-label">This month</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value">{{ number_format($card['today']) }}</div>
                            <div class="stat-label">Today</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection