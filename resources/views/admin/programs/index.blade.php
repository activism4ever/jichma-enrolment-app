@extends('layouts.app')

@section('title', 'Programmes')

@section('content')
    <div class="page-header">
        <h1>Programmes</h1>
        <p class="page-subtitle">
            Programmes are the unit of scope for enrolments, accounts and exports.
            They are deactivated, never deleted, so existing records keep their history.
        </p>
    </div>

    <div class="toolbar">
        <span></span>
        @can('create', App\Models\Program::class)
            <a href="{{ route('admin.programs.create') }}" class="btn btn-primary">New programme</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Enrolments</th>
                    <th>Accounts</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($programs as $program)
                    <tr>
                        <td data-label="Code" class="mono">{{ $program->code }}</td>
                        <td data-label="Name">{{ $program->name }}</td>
                        <td data-label="Description" class="text-small text-muted">
                            {{ $program->description ?: '—' }}
                        </td>
                        <td data-label="Enrolments" class="nowrap">{{ number_format($program->enrolees_count) }}</td>
                        <td data-label="Accounts" class="nowrap">{{ number_format($program->users_count) }}</td>
                        <td data-label="Status">
                            <span class="badge badge-{{ $program->is_active ? 'active' : 'inactive' }}">
                                {{ $program->is_active ? 'Active' : 'Deactivated' }}
                            </span>
                        </td>
                        <td data-label="Data">
                            @if ($program->is_sensitive)
                                <span class="badge badge-sensitive" title="Sensitive programme">Sensitive</span>
                            @else
                                <span class="text-muted text-small">Standard</span>
                            @endif
                        </td>
                        <td data-label="Actions">
                            <div class="cell-actions">
                                @can('update', $program)
                                    <a class="btn btn-sm btn-secondary" href="{{ route('admin.programs.edit', $program) }}">Edit</a>
                                @endcan

                                @if ($program->is_active)
                                    @can('deactivate', $program)
                                        <form method="POST" action="{{ route('admin.programs.destroy', $program) }}"
                                              class="inline-form"
                                              data-confirm="Deactivate {{ $program->label() }}? It will no longer be offered for new enrolments, accounts or exports. Existing records are unchanged.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Deactivate</button>
                                        </form>
                                    @endcan
                                @else
                                    @can('activate', $program)
                                        <form method="POST" action="{{ route('admin.programs.activate', $program) }}" class="inline-form">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">Reactivate</button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8"><p class="empty-state">No programmes have been created yet.</p></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
