@extends('layouts.app')

@section('title', $program->code.' — Enrolment summary')

@section('content')
    {{-- The page does drill-down, not editing: the headline answers "how big
         is this program in my view", the table answers "where is that", and the
         only route onward is a filtered jump into Enrolees. Nothing here
         re-creates a count the list screen would refuse to show. --}}
    <div class="page-header">
        <h1>{{ $program->label() }}</h1>
        <p class="page-subtitle">
            <a href="{{ route('programme-summary.index') }}">Programme Summary</a>
            &middot; {{ $program->code }}
            @if ($selectedLga)
                &middot; {{ $selectedLga->name }}
            @endif
            @if ($selected)
                &middot; {{ $selected->name }}
            @endif
        </p>
    </div>

    @if ($scopeNote)
        <p class="text-muted text-small">{{ $scopeNote }}</p>
    @endif

    @if ($failed)
        <div class="card">
            <div class="empty-state">
                <p><strong>The totals could not be loaded.</strong></p>
                <p>There was a problem calculating enrolment figures. Please try again shortly.</p>
            </div>
        </div>
    @else
        <div class="stat-row stat-row-3">
            <div class="stat">
                <div class="stat-value">{{ number_format($headline['total']) }}</div>
                <div class="stat-label">Enrolees in view</div>
            </div>
            <div class="stat">
                <div class="stat-value">{{ number_format($headline['month']) }}</div>
                <div class="stat-label">Enrolled this month</div>
            </div>
            <div class="stat">
                <div class="stat-value">{{ number_format($headline['today']) }}</div>
                <div class="stat-label">Enrolled today</div>
            </div>
        </div>

        <div class="page-header">
            <a href="{{ route('enrolees.index', $enroleesParams) }}" class="btn btn-secondary">
                Browse enrolees in view
            </a>
        </div>

        <div class="card">
            <h2 class="card-title">
                @if ($selectedLga)
                    Breakdown by ward
                @else
                    Breakdown by LGA
                @endif
            </h2>

            @if ($breakdown->isEmpty())
                <p class="text-muted">No enrolees in this view.</p>
            @else
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>@if ($selectedLga) Ward @else LGA @endif</th>
                                <th>Enrolees</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($breakdown as $row)
                                <tr>
                                    <td>
                                        @if ($row['name'] === null)
                                            <span class="text-muted">Unrecorded</span>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </td>
                                    <td>{{ number_format($row['total']) }}</td>
                                    <td>
                                        @if ($selectedLga)
                                            {{-- Only a labelled row can link: an "Unrecorded"
                                                 row has no ward to point at. --}}
                                            @if ($row['id'] !== null)
                                                <a href="{{ route('enrolees.index', array_merge($enroleesParams, ['ward_id' => $row['id']])) }}">View enrollees</a>
                                            @endif
                                        @else
                                            @if ($row['id'] !== null)
                                                <a href="{{ route('programme-summary.show', array_merge(['program' => $program->id, 'lga_id' => $row['id']], $dateParams)) }}">View wards</a>
                                            @else
                                                <span class="text-muted">No location recorded</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection