@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <div class="page-header">
        <h1>Audit log</h1>
        <p class="page-subtitle">
            Append-only. Entries are written as a side effect of the action they
            describe and cannot be edited or removed here, which is what makes the
            trail usable as evidence.
        </p>
    </div>

    <form method="GET" action="{{ route('admin.audit.index') }}" class="filters card">
        <div class="field">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>
                        {{ str_replace('_', ' ', $action) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="actor_id">Who</label>
            <select id="actor_id" name="actor_id">
                <option value="">Anyone</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" @selected((int) ($filters['actor_id'] ?? 0) === $actor->id)>
                        {{ $actor->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="program_id">Program</label>
            <select id="program_id" name="program_id">
                <option value="">All programs</option>
                @foreach ($programs as $program)
                    <option value="{{ $program->id }}" @selected((int) ($filters['program_id'] ?? 0) === $program->id)>
                        {{ $program->code }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>

        <div class="field">
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>

        <div class="field">
            <label for="q">Contains</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Action or description">
        </div>

        <div class="field filter-wide form-actions">
            <button type="submit" class="btn btn-primary">Apply filters</button>
            <a href="{{ route('admin.audit.index') }}" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>What happened</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td data-label="When" class="nowrap text-small">
                            {{ $log->created_at?->format('j M Y H:i:s') }}
                        </td>
                        <td data-label="Who" class="text-small">
                            {{ $log->actor?->name ?? 'system' }}
                            @if ($log->actor)
                                <br><span class="text-muted">{{ $log->actor->role->label() }}</span>
                            @endif
                        </td>
                        <td data-label="Action" class="mono text-small">{{ $log->action }}</td>
                        <td data-label="What happened" class="text-small">{{ $log->description }}</td>
                        <td data-label="Source" class="text-small text-muted">
                            @if ($log->ip_address)
                                <span class="mono">{{ $log->ip_address }}</span><br>
                            @endif
                            {{ $log->program?->code ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5"><p class="empty-state">No entries match these filters.</p></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($logs->hasPages())
        <nav class="pagination" aria-label="Pagination">
            @if ($logs->onFirstPage())
                <span class="is-disabled">Previous</span>
            @else
                <a href="{{ $logs->previousPageUrl() }}" rel="prev">Previous</a>
            @endif

            <span class="is-current">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>

            @if ($logs->hasMorePages())
                <a href="{{ $logs->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="is-disabled">Next</span>
            @endif
        </nav>
    @endif
@endsection
