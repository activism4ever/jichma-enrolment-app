@extends('layouts.app')

@section('title', 'Enrolees')

@section('content')
    {{-- The action sits on the same line as the title rather than below the
         filters, because registering is the reason most people open this
         screen, and a button under a seven-field form is a button they have
         to go looking for. --}}
    <div class="page-header page-header--actions">
        <h1>Enrolees</h1>

        @can('create', App\Models\Enrolee::class)
            <a href="{{ route('enrolees.create') }}" class="btn btn-primary">+ New enrolee</a>
        @endcan

        <p class="page-subtitle">
            @if (auth()->user()->isReviewer())
                Read-only view of all records in {{ auth()->user()->program?->code }}.
            @elseif (auth()->user()->isAdmin())
                All programs
            @else
                Records you have created.
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('enrolees.index') }}" class="filters card">
        <div class="field">
            <label for="search">Search</label>
            <input id="search" type="search" name="search" value="{{ $filters->search }}"
                   autocomplete="off"
                   placeholder="Name, NIN or phone">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="draft" @selected($filters->status === 'draft')>Draft</option>
                <option value="submitted" @selected($filters->status === 'submitted')>Submitted</option>
            </select>
        </div>

        @if ($isAdmin)
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
            <button type="submit" class="btn btn-primary">Apply filters</button>
            <a href="{{ route('enrolees.index') }}" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    {{-- The total sits above the table as well as in the pagination summary,
         because a list that fits on one page has no pagination summary, and
         "how many are there" is the first question the screen is asked. --}}
    @if ($enrolees->total() > 0)
        <p class="result-count">
            @if ($filters->isActive())
                Showing {{ number_format($enrolees->total()) }} of {{ number_format($unfilteredTotal) }} enrolee{{ $unfilteredTotal === 1 ? '' : 's' }}
            @else
                {{ number_format($enrolees->total()) }} enrolee{{ $enrolees->total() === 1 ? '' : 's' }}
            @endif
        </p>
    @endif

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>NIN</th>
                    <th>Phone</th>
                    <th>Program</th>
                    <th>LGA / Ward / Facility</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($enrolees as $enrolee)
                    @php
                        // Mask NIN and phone in list views for the sensitive
                        // program, and for anyone reviewing. Detail pages
                        // show the full values.
                        $masked = auth()->user()->isReviewer()
                            || $enrolee->program?->isSensitive();
                    @endphp
                    <tr>
                        <td data-label="Name">
                            <strong>{{ $enrolee->full_name ?: '—' }}</strong>
                            @if ($masked && $enrolee->program?->isSensitive())
                                <span class="badge badge-sensitive" title="Sensitive programme">Sensitive</span>
                            @endif
                        </td>
                        <td data-label="NIN" class="mono">
                            {{ $masked ? App\Models\Enrolee::maskNin($enrolee->nin) : ($enrolee->nin ?: '—') }}
                        </td>
                        <td data-label="Phone" class="mono">
                            {{ $masked ? App\Models\Enrolee::maskPhone($enrolee->phone) : ($enrolee->phone ?: '—') }}
                        </td>
                        <td data-label="Program">{{ $enrolee->program?->code ?? '—' }}</td>
                        <td data-label="Location" class="text-small">
                            {{ $enrolee->lga?->name ?? '—' }}<br>
                            <span class="text-muted">{{ $enrolee->ward?->name ?? '' }}</span><br>
                            <span class="text-muted">{{ $enrolee->facility?->name ?? '' }}</span>
                        </td>
                        <td data-label="Status">
                            <span class="badge badge-{{ $enrolee->status->value }}">
                                {{ $enrolee->status->label() }}
                            </span>
                        </td>
                        <td data-label="Updated" class="nowrap text-small">
                            {{ $enrolee->updated_at?->format('j M Y H:i') }}
                        </td>
                        <td data-label="Actions">
                            <div class="cell-actions">
                                <a class="btn btn-sm btn-secondary" href="{{ route('enrolees.show', $enrolee) }}">View</a>
                                @can('update', $enrolee)
                                    <a class="btn btn-sm btn-secondary" href="{{ route('enrolees.edit', $enrolee) }}">Edit</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            {{-- Two different absences, two different answers.
                                 An account that has never held a record is
                                 told to register one; a filter that matched
                                 nothing is told how to widen the search. One
                                 message for both leaves either a new user
                                 without a next step or a filtering user
                                 reading an untrue sentence. --}}
                            @if (! $hasAnyRecords)
                                <div class="empty-state">
                                    <p><strong>No enrolees yet.</strong></p>
                                    <p>There are currently no enrolment records.</p>

                                    @can('create', App\Models\Enrolee::class)
                                        <p>
                                            <a href="{{ route('enrolees.create') }}" class="btn btn-primary">+ Register new enrolee</a>
                                        </p>
                                    @endcan
                                </div>
                            @else
                                <div class="empty-state">
                                    <p><strong>No enrolees match these filters.</strong></p>
                                    <p>Try adjusting your search criteria or reset the filters.</p>

                                    <p>
                                        <a href="{{ route('enrolees.index') }}" class="btn btn-secondary">Reset Filters</a>
                                    </p>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('partials.pagination', ['paginator' => $enrolees])
@endsection