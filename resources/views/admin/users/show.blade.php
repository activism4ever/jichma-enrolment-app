@extends('layouts.app')

@section('title', $user->name)

@section('content')
    <div class="page-header">
        <h1>{{ $user->name }}</h1>
        <p class="page-subtitle">
            {{ $user->role->label() }} &middot;
            {{ $user->program?->code ?? 'All programs' }} &middot;
            <span class="badge badge-{{ $user->is_active ? 'active' : 'inactive' }}">
                {{ $user->is_active ? 'Active' : 'Deactivated' }}
            </span>
        </p>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2 class="card-title">Account</h2>
            <dl class="definition-list">
                <dt>Email</dt>
                <dd>{{ $user->email }}</dd>

                <dt>Role</dt>
                <dd>{{ $user->role->label() }}</dd>

                <dt>Program</dt>
                <dd>{{ $user->program?->code ?? 'All programs' }}</dd>

                <dt>Created</dt>
                <dd>{{ $user->created_at?->format('j M Y') }}</dd>

                <dt>Last sign-in</dt>
                <dd>{{ $user->last_login_at?->format('j M Y, H:i') ?? 'Never' }}</dd>

                <dt>Password changed</dt>
                <dd>{{ $user->password_changed_at?->format('j M Y, H:i') ?? 'Never' }}</dd>

                <dt>Enrolees created</dt>
                <dd>{{ $enroleeCount }}</dd>
            </dl>

            <div class="form-actions">
                @can('update', $user)
                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary">Edit</a>
                @endcan

                @if ($user->is_active)
                    @can('deactivate', $user)
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                              class="inline-form"
                              data-confirm="Deactivate {{ $user->email }}? They will be signed out on their next request."
                              data-disable-on-submit>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">
                                <span data-submit-label>Deactivate</span>
                            </button>
                        </form>
                    @endcan
                @else
                    @can('activate', $user)
                        <form method="POST" action="{{ route('admin.users.activate', $user) }}"
                              class="inline-form" data-disable-on-submit>
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <span data-submit-label>Reactivate</span>
                            </button>
                        </form>
                    @endcan
                @endif

                <a href="{{ route('admin.users.index') }}" class="btn btn-link">Back to list</a>
            </div>
        </div>

        <div>
            @can('resetPassword', $user)
                <div class="card">
                    <h2 class="card-title">Issue a temporary password</h2>
                    <p class="text-muted">
                        Sets a new password and forces a change at next sign-in. Any
                        existing sessions for this account are ended.
                    </p>

                    <form method="POST" action="{{ route('admin.users.password', $user) }}"
                          data-disable-on-submit>
                        @csrf
                        @method('PUT')

                        <div class="field {{ $errors->has('password') ? 'has-error' : '' }}">
                            <label for="password">New temporary password</label>
                            <input id="password" type="password" name="password" required
                                   autocomplete="new-password">
                            <p class="field-hint">At least 8 characters, including a letter and a number.</p>
                            @error('password') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="field">
                            <label for="password_confirmation">Confirm password</label>
                            <input id="password_confirmation" type="password" name="password_confirmation"
                                   required autocomplete="new-password">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <span data-submit-label>Issue password</span>
                            </button>
                        </div>
                    </form>
                </div>
            @endcan

            <div class="card">
                <h2 class="card-title">Recent activity</h2>

                @if ($recentAudit->isEmpty())
                    <p class="text-muted">No recorded actions for this account yet.</p>
                @else
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Action</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentAudit as $entry)
                                    <tr>
                                        <td data-label="When" class="nowrap text-small">
                                            {{ $entry->created_at?->format('j M H:i') }}
                                        </td>
                                        <td data-label="Action" class="mono text-small">{{ $entry->action }}</td>
                                        <td data-label="Detail" class="text-small">{{ $entry->description }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="field-hint">
                    <a href="{{ route('admin.audit.index', ['actor_id' => $user->id]) }}">
                        See all activity in the audit log
                    </a>
                </p>
            </div>
        </div>
    </div>
@endsection
