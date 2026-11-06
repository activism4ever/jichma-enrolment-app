@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="page-header">
        <h1>Users</h1>
        <p class="page-subtitle">
            Accounts grant access to personal data, so every change here is recorded
            in the audit log.
        </p>
    </div>

    {{-- Active accounts per role. These describe the whole workforce, so they
         deliberately ignore the filters below; the same numbers appear on the
         dashboard, and both read User::activeCountsByRole(). --}}
    <div class="stat-row">
        @foreach ($roles as $role)
            <div class="stat">
                <div class="stat-value">{{ $roleCounts[$role['value']] ?? 0 }}</div>
                <div class="stat-label">{{ $role['label'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('admin.users.index') }}" class="filters card">
            <div class="field">
                <label for="search">Search</label>
                <input id="search" type="search" name="search" value="{{ $filters['search'] ?? '' }}"
                       autocomplete="off"
                       placeholder="Name or email">
            </div>

            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="">All roles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role['value'] }}" @selected(($filters['role'] ?? '') === $role['value'])>
                            {{ $role['label'] }}
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
                <label for="status">Account status</label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Deactivated</option>
                </select>
            </div>

            <div class="field filter-wide form-actions">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        @can('create', App\Models\User::class)
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">New user</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Program</th>
                    <th>Status</th>
                    <th>Last sign-in</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td data-label="Name">
                            <strong>{{ $user->name }}</strong>
                            @if ($user->must_change_password)
                                <span class="badge badge-draft">Temp password</span>
                            @endif
                        </td>
                        <td data-label="Email">{{ $user->email }}</td>
                        <td data-label="Role">{{ $user->role->label() }}</td>
                        <td data-label="Program">{{ $user->program?->code ?? 'All programs' }}</td>
                        <td data-label="Status">
                            <span class="badge badge-{{ $user->is_active ? 'active' : 'inactive' }}">
                                {{ $user->is_active ? 'Active' : 'Deactivated' }}
                            </span>
                        </td>
                        <td data-label="Last sign-in" class="nowrap text-small">
                            {{ $user->last_login_at?->format('j M Y H:i') ?? 'Never' }}
                        </td>
                        <td data-label="Actions">
                            <div class="cell-actions">
                                <a class="btn btn-sm btn-secondary" href="{{ route('admin.users.show', $user) }}">View</a>
                                @can('update', $user)
                                    <a class="btn btn-sm btn-secondary" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7"><p class="empty-state">No accounts match these filters.</p></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <nav class="pagination" aria-label="Pagination">
            @if ($users->onFirstPage())
                <span class="is-disabled">Previous</span>
            @else
                <a href="{{ $users->previousPageUrl() }}" rel="prev">Previous</a>
            @endif

            <span class="is-current">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>

            @if ($users->hasMorePages())
                <a href="{{ $users->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="is-disabled">Next</span>
            @endif
        </nav>
    @endif
@endsection
