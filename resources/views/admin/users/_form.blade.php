{{--
    Shared account form. $mode is 'create' or 'edit'.

    The program field is shown only for the roles that belong to one. Admin
    accounts are not attached to a program, and the server enforces that
    pairing (see StoreUserRequest and User::setProgramIdAttribute) so a
    mismatch cannot be saved even by crafting the request.
--}}
@php
    $mode = $mode ?? 'create';
    $isAdminRole = old('role', $user->role?->value ?? '') === 'admin';
@endphp

<form method="POST"
      action="{{ $mode === 'create' ? route('admin.users.store') : route('admin.users.update', $user) }}"
      data-disable-on-submit>
    @csrf
    @if ($mode === 'edit')
        @method('PUT')
    @endif

    <fieldset>
        <legend>Account</legend>

        <div class="field {{ $errors->has('name') ? 'has-error' : '' }}">
            <label for="name">Full name <span class="required-marker" aria-hidden="true">*</span></label>
            <input id="name" type="text" name="name" required autocomplete="name"
                   value="{{ old('name', $user->name) }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('email') ? 'has-error' : '' }}">
            <label for="email">Email address <span class="required-marker" aria-hidden="true">*</span></label>
            <input id="email" type="email" name="email" required autocomplete="off"
                   value="{{ old('email', $user->email) }}">
            <p class="field-hint">Used to sign in. Must be unique across all accounts.</p>
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('role') ? 'has-error' : '' }}">
            <label for="role">Role <span class="required-marker" aria-hidden="true">*</span></label>
            <select id="role" name="role" required data-role-select>
                @foreach ($roles as $role)
                    <option value="{{ $role['value'] }}"
                        @selected(old('role', $user->role?->value) === $role['value'])>
                        {{ $role['label'] }}
                    </option>
                @endforeach
            </select>
            <p class="field-hint">
                Administrator: full access, no program.
                Program User: creates records in one program.
                Reviewer: read-only across one program.
            </p>
            @error('role') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('program_id') ? 'has-error' : '' }}"
             data-program-field @if ($isAdminRole) hidden @endif>
            <label for="program_id">Program <span class="required-marker" aria-hidden="true">*</span></label>
            <select id="program_id" name="program_id">
                <option value="">Select a program</option>
                @foreach ($programs as $program)
                    <option value="{{ $program->id }}"
                        @selected((int) old('program_id', $user->program_id) === $program->id)>
                        {{ $program->code }} &mdash; {{ $program->name }}
                    </option>
                @endforeach
            </select>
            @error('program_id') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        @if ($mode === 'edit')
            <div class="field">
                <label for="is_active">Account status</label>
                <div class="field-inline">
                    {{-- An unchecked checkbox submits no value at all, which
                         would trip the required rule above, so send an
                         explicit false first. The checkbox overrides it. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input id="is_active" type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $user->is_active))>
                    <label for="is_active">Active &mdash; may sign in</label>
                </div>
                <p class="field-hint">Deactivating takes effect on the user's next request, not just at next sign-in.</p>
                @error('is_active') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif
    </fieldset>

    @if ($mode === 'create')
        <fieldset>
            <legend>Temporary password</legend>

            <div class="alert alert-info">
                The account cannot be used beyond the change-password screen until the
                holder replaces this password. Communicate it through a channel that is
                not this application.
            </div>

            <div class="grid grid-2">
                <div class="field {{ $errors->has('password') ? 'has-error' : '' }}">
                    <label for="password">Temporary password <span class="required-marker" aria-hidden="true">*</span></label>
                    <input id="password" type="password" name="password" required autocomplete="new-password">
                    <p class="field-hint">At least 8 characters, including a letter and a number.</p>
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password">
                </div>
            </div>
        </fieldset>
    @endif

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <span data-submit-label>{{ $mode === 'create' ? 'Create account' : 'Save changes' }}</span>
        </button>
        <a href="{{ $mode === 'create' ? route('admin.users.index') : route('admin.users.show', $user) }}"
           class="btn btn-link">Cancel</a>
    </div>
</form>
