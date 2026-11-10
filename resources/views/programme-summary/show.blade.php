@extends('layouts.app')

@section('title', $program->code.' — Enrolment summary')

@section('content')
    {{-- The page does drill-down, not editing: the headline answers "how big
         is this program in my view", the table answers "where is that", and the
         only route onward is a filtered jump into Enrolees. Nothing here
         re-creates a count the list screen would refuse to show. --}}
    <x-ui.page-header
        class="programme-summary-detail-header"
        :title="$program->label()"
        subtitle="Enrolment totals and location breakdown for this programme."
    >
        <x-slot:breadcrumbs>
            <ol>
                <li><a href="{{ route('programme-summary.index') }}">Programme Summary</a></li>
                <li @if (! $selectedLga) aria-current="page" @endif>
                    @if ($selectedLga)
                        <a href="{{ route('programme-summary.show', array_merge(['program' => $program->id], $dateParams)) }}">{{ $program->code }}</a>
                    @else
                        {{ $program->code }}
                    @endif
                </li>
                @if ($selectedLga)
                    <li @if (! $selected) aria-current="page" @endif>
                        @if ($selected)
                            <a href="{{ route('programme-summary.show', array_merge(['program' => $program->id, 'lga_id' => $selectedLga->id], $dateParams)) }}">{{ $selectedLga->name }}</a>
                        @else
                            {{ $selectedLga->name }}
                        @endif
                    </li>
                @endif
                @if ($selected)
                    <li aria-current="page">{{ $selected->name }}</li>
                @endif
            </ol>
        </x-slot:breadcrumbs>

        @unless ($failed)
            <x-slot:actions>
                <x-ui.button :href="route('enrolees.index', $enroleesParams)" variant="secondary">
                    Browse enrolees in view
                </x-ui.button>
            </x-slot:actions>
        @endunless
    </x-ui.page-header>

    @if ($scopeNote)
        <p class="text-muted text-small programme-scope-note">{{ $scopeNote }}</p>
    @endif

    @if ($failed)
        <x-ui.card>
            <x-ui.empty-state title="The totals could not be loaded.">
                <p>There was a problem calculating enrolment figures. Please try again shortly.</p>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="stat-row stat-row-3">
            <x-ui.stat :value="number_format($headline['total'])" label="Enrolees in view" />
            <x-ui.stat :value="number_format($headline['month'])" label="Enrolled this month" />
            <x-ui.stat :value="number_format($headline['today'])" label="Enrolled today" />
        </div>

        <x-ui.card :title="$selectedLga ? 'Breakdown by ward' : 'Breakdown by LGA'">
            @if ($breakdown->isEmpty())
                <x-ui.empty-state title="No enrolees in this view.">
                    <p>No records match the current programme and filters.</p>
                </x-ui.empty-state>
            @else
                <x-ui.table
                    class="programme-breakdown"
                    :caption="$selectedLga ? 'Enrolees by ward' : 'Enrolees by LGA'"
                >
                    <thead>
                        <tr>
                            <th scope="col">@if ($selectedLga) Ward @else LGA @endif</th>
                            <th scope="col">Enrolees</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($breakdown as $row)
                            <tr>
                                <td data-label="{{ $selectedLga ? 'Ward' : 'LGA' }}">
                                    @if ($row['name'] === null)
                                        <span class="text-muted">Unrecorded</span>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </td>
                                <td data-label="Enrolees">{{ number_format($row['total']) }}</td>
                                <td data-label="Actions">
                                    @if ($selectedLga)
                                        {{-- Only a labelled row can link: an "Unrecorded"
                                             row has no ward to point at. --}}
                                        @if ($row['id'] !== null)
                                            <a href="{{ route('enrolees.index', array_merge($enroleesParams, ['ward_id' => $row['id']])) }}">
                                                View enrollees<span class="visually-hidden"> in {{ $row['name'] }}</span>
                                            </a>
                                        @endif
                                    @else
                                        @if ($row['id'] !== null)
                                            <a href="{{ route('programme-summary.show', array_merge(['program' => $program->id, 'lga_id' => $row['id']], $dateParams)) }}">
                                                View wards<span class="visually-hidden"> in {{ $row['name'] }}</span>
                                            </a>
                                        @else
                                            <span class="text-muted">No location recorded</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
@endsection
