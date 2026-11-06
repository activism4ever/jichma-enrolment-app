{{--
    Shared programme form. $mode is 'create' or 'edit'.

    The code is shown read-only on edit and is not submitted at all: it is the
    programme's permanent identity (UpdateProgramRequest has no code rule, so a
    crafted submission is ignored). Sensitivity is a checkbox paired with a
    hidden false, because it decides whether the programme's personal data gets
    extra masking and a missing field must not read as "not sensitive".
--}}
@php
    $mode = $mode ?? 'create';
@endphp

<form method="POST"
      action="{{ $mode === 'create' ? route('admin.programs.store') : route('admin.programs.update', $program) }}"
      data-disable-on-submit>
    @csrf
    @if ($mode === 'edit')
        @method('PUT')
    @endif

    <fieldset>
        <legend>Programme</legend>

        @if ($mode === 'create')
            <div class="field {{ $errors->has('code') ? 'has-error' : '' }}">
                <label for="code">Code <span class="required-marker" aria-hidden="true">*</span></label>
                <input id="code" type="text" name="code" required maxlength="20"
                       class="mono" autocomplete="off" style="text-transform: uppercase"
                       value="{{ old('code') }}">
                <p class="field-hint">
                    The programme's permanent identity, e.g. <span class="mono">CEMOG</span>.
                    It cannot be changed after the programme is created.
                </p>
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="field">
                <label for="code">Code</label>
                <input id="code" type="text" value="{{ $program->code }}" class="mono" readonly disabled>
                <p class="field-hint">
                    Permanent. A correction is a controlled administrator migration, not an edit.
                </p>
            </div>
        @endif

        <div class="field {{ $errors->has('name') ? 'has-error' : '' }}">
            <label for="name">Name <span class="required-marker" aria-hidden="true">*</span></label>
            <input id="name" type="text" name="name" required maxlength="100"
                   value="{{ old('name', $program->name) }}">
            <p class="field-hint">May be changed at any time; existing records are unaffected.</p>
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('description') ? 'has-error' : '' }}">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" maxlength="500" rows="2">{{ old('description', $program->description) }}</textarea>
            @error('description') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('is_sensitive') ? 'has-error' : '' }}">
            <label for="is_sensitive">Data protection</label>
            <div class="field-inline">
                {{-- An unchecked checkbox submits nothing, so an explicit
                     false is sent first and the checkbox overrides it. --}}
                <input type="hidden" name="is_sensitive" value="0">
                <input id="is_sensitive" type="checkbox" name="is_sensitive" value="1"
                       @checked((bool) old('is_sensitive', $program->is_sensitive))>
                <label for="is_sensitive">Holds sensitive data &mdash; mask NIN and phone in list views</label>
            </div>
            <p class="field-hint">
                Mark only programmes whose data must be masked wherever it is listed.
                Changing this is recorded in the audit log.
            </p>
            @error('is_sensitive') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <span data-submit-label>{{ $mode === 'create' ? 'Create programme' : 'Save changes' }}</span>
        </button>
        <a href="{{ route('admin.programs.index') }}" class="btn btn-link">Cancel</a>
    </div>
</form>
