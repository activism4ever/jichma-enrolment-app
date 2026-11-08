@extends('layouts.app')

@section('title', 'Enrolees')

@section('content')
    @php
        $viewer = auth()->user();

        $scopeDescription = $viewer->isReviewer()
            ? 'Read-only view of all records in '.($viewer->program?->code ?? 'your programme').'.'
            : ($viewer->isAdmin() ? 'All programs' : 'Records you have created.');
    @endphp

    <div class="enrollee-page enrollee-index">
        <x-ui.page-header title="Enrolees" :subtitle="$scopeDescription">
            <x-slot:actions>
                @can('create', App\Models\Enrolee::class)
                    <x-ui.button :href="route('enrolees.create')" variant="primary">+ New enrolee</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('enrolees.index')" class="enrollee-filters">
            <p class="filter-group-label">Find a record</p>

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

            <p class="filter-group-label">Programme and location</p>

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

            <p class="filter-group-label">Created date</p>

            <div class="field">
                <label for="from">Created from</label>
                <input id="from" type="date" name="from" value="{{ $filters->from }}">
            </div>

            <div class="field">
                <label for="to">Created to</label>
                <input id="to" type="date" name="to" value="{{ $filters->to }}">
            </div>

            <div class="filter-actions enrollee-filter-actions">
                <x-ui.button type="submit" variant="primary">Apply filters</x-ui.button>
                <x-ui.button :href="route('enrolees.index')" variant="secondary">Reset</x-ui.button>
            </div>
        </x-ui.filter-bar>

        {{-- The total sits above the table as well as in the pagination summary,
             because a list that fits on one page has no pagination summary, and
             "how many are there" is the first question the screen is asked. --}}
        @if ($enrolees->total() > 0)
            <p class="result-count enrollee-result-count">
                @if ($filters->isActive())
                    Showing {{ number_format($enrolees->total()) }} of {{ number_format($unfilteredTotal) }} enrolee{{ $unfilteredTotal === 1 ? '' : 's' }}
                @else
                    {{ number_format($enrolees->total()) }} enrolee{{ $enrolees->total() === 1 ? '' : 's' }}
                @endif
            </p>
        @endif

        <x-ui.table class="enrollee-table" caption="Enrolee records">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">NIN</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Program</th>
                    <th scope="col">LGA / Ward / Facility</th>
                    <th scope="col">Status</th>
                    <th scope="col">Updated</th>
                    <th scope="col">Actions</th>
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
                            <span class="cell-value">
                                <strong class="enrollee-name">{{ $enrolee->full_name ?: '—' }}</strong>
                                @if ($masked && $enrolee->program?->isSensitive())
                                    <x-ui.badge variant="sensitive" title="Sensitive programme">Sensitive</x-ui.badge>
                                @endif
                            </span>
                        </td>
                        <td data-label="NIN" class="mono">
                            <span class="cell-value">
                                {{ $masked ? App\Models\Enrolee::maskNin($enrolee->nin) : ($enrolee->nin ?: '—') }}
                            </span>
                        </td>
                        <td data-label="Phone" class="mono">
                            <span class="cell-value">
                                {{ $masked ? App\Models\Enrolee::maskPhone($enrolee->phone) : ($enrolee->phone ?: '—') }}
                            </span>
                        </td>
                        <td data-label="Program">
                            <span class="cell-value">{{ $enrolee->program?->code ?? '—' }}</span>
                        </td>
                        <td data-label="Location" class="text-small">
                            <span class="cell-value enrollee-location">
                                {{ $enrolee->lga?->name ?? '—' }}<br>
                                <span class="text-muted">{{ $enrolee->ward?->name ?? '' }}</span><br>
                                <span class="text-muted">{{ $enrolee->facility?->name ?? '' }}</span>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="cell-value">
                                <x-ui.badge :variant="$enrolee->status->value">
                                    {{ $enrolee->status->label() }}
                                </x-ui.badge>
                            </span>
                        </td>
                        <td data-label="Updated" class="nowrap text-small">
                            <span class="cell-value enrollee-updated">
                                {{ $enrolee->updated_at?->format('j M Y H:i') }}
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="cell-value cell-actions enrollee-row-actions">
                                <x-ui.button :href="route('enrolees.show', $enrolee)" variant="secondary" size="sm">View</x-ui.button>
                                @can('update', $enrolee)
                                    <x-ui.button :href="route('enrolees.edit', $enrolee)" variant="secondary" size="sm">Edit</x-ui.button>
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
                                <x-ui.empty-state title="No enrolees yet.">
                                    <p>There are currently no enrolment records.</p>

                                    <x-slot:actions>
                                        @can('create', App\Models\Enrolee::class)
                                            <x-ui.button :href="route('enrolees.create')" variant="primary">+ Register new enrolee</x-ui.button>
                                        @endcan
                                    </x-slot:actions>
                                </x-ui.empty-state>
                            @else
                                <x-ui.empty-state title="No enrolees match these filters.">
                                    <p>Try adjusting your search criteria or reset the filters.</p>

                                    <x-slot:actions>
                                        <x-ui.button :href="route('enrolees.index')" variant="secondary">Reset Filters</x-ui.button>
                                    </x-slot:actions>
                                </x-ui.empty-state>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @include('partials.pagination', ['paginator' => $enrolees])
    </div>
@endsection
