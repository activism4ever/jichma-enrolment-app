{{--
    Shared fields for the create and edit forms.

    $mode is 'create' or 'edit'; $enrolee may be an unsaved model.

    Two buttons share one form. "Save draft" posts the form's own action and
    carries formnovalidate, so a half-filled form is allowed to save. "Submit
    registration" overrides the action with formaction, so the same fields go to
    the submission endpoint and are validated in full.

    There is deliberately no @method('PUT') field: it would travel with whichever
    button was pressed, and the Submit button's POST would arrive at the submit
    route spoofed as a PUT. The update route accepts POST and PUT for that reason.
--}}
@php
    $mode = $mode ?? 'create';
    $enrolee = $enrolee ?? new \App\Models\Enrolee;
    $isSubmitted = $mode === 'edit' && $enrolee->isSubmitted();

    // Submit is offered for a draft only. An administrator editing an
    // already-submitted record is correcting it under audit, and re-submitting
    // would restamp submitted_at and make the correction look like the original
    // registration.
    $canSubmit = $mode === 'create'
        || ($enrolee->isDraft() && auth()->user()->can('submit', $enrolee));

    $submitAction = $mode === 'create'
        ? route('enrolees.submit-new')
        : route('enrolees.submit', $enrolee);
@endphp

@if ($isSubmitted)
    <x-ui.alert variant="warning">
        This record was submitted on {{ $enrolee->submitted_at?->format('j M Y, H:i') }}.
        Saving a change here is recorded in the audit log.
    </x-ui.alert>
@endif

<form method="POST"
      action="{{ $mode === 'create' ? route('enrolees.store') : route('enrolees.update', $enrolee) }}"
      enctype="multipart/form-data"
      class="enrollee-form"
      data-disable-on-submit>
    @csrf

    <p class="form-required-note">
        <span class="required-marker" aria-hidden="true">*</span> Required field
    </p>

    <fieldset class="enrollee-form-section enrollee-form-section--identity">
        <legend>Identity</legend>
        <p class="section-hint">The enrollee's name and personal details, as they appear on their identification.</p>

        @if ($mode === 'edit')
            {{-- A record's program is fixed at creation: moving a submitted
                 registration would rewrite what was attested. --}}
            <p class="context-note">
                Program: <strong>{{ $enrolee->program?->code }}</strong>
                <span class="text-muted">{{ $enrolee->program?->name }}</span>
            </p>
        @elseif ($isAdmin)
            <div class="field {{ $errors->has('program_id') ? 'has-error' : '' }}">
                <label for="program_id">
                    Program <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="program_id" name="program_id" required>
                    <option value="">Select a program</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program->id }}"
                            @selected((int) old('program_id', $enrolee->program_id) === $program->id)>
                            {{ $program->code }} &mdash; {{ $program->name }}
                        </option>
                    @endforeach
                </select>
                @error('program_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @else
            <p class="context-note">
                Registering into <strong>{{ auth()->user()->program?->code }}</strong>.
            </p>
        @endif

        <div class="grid grid-3 enrollee-form-grid">
            <div class="field {{ $errors->has('surname') ? 'has-error' : '' }}">
                <label for="surname">
                    Surname <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <input id="surname" type="text" name="surname" autocomplete="family-name"
                       maxlength="100" required
                       value="{{ old('surname', $enrolee->surname) }}">
                @error('surname') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field {{ $errors->has('first_name') ? 'has-error' : '' }}">
                <label for="first_name">
                    First name <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <input id="first_name" type="text" name="first_name" autocomplete="given-name"
                       maxlength="100" required
                       value="{{ old('first_name', $enrolee->first_name) }}">
                @error('first_name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            {{-- The only field that may be left blank on a submission. --}}
            <div class="field {{ $errors->has('middle_name') ? 'has-error' : '' }}">
                <label for="middle_name">Middle name (optional)</label>
                <input id="middle_name" type="text" name="middle_name"
                       maxlength="100" value="{{ old('middle_name', $enrolee->middle_name) }}">
                @error('middle_name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-3 enrollee-form-grid">
            <div class="field {{ $errors->has('gender') ? 'has-error' : '' }}">
                <label for="gender">
                    Gender <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="gender" name="gender" required>
                    <option value="">Select gender</option>
                    @foreach ($genders as $gender)
                        <option value="{{ $gender['value'] }}"
                            @selected(old('gender', $enrolee->gender?->value) === $gender['value'])>
                            {{ $gender['label'] }}
                        </option>
                    @endforeach
                </select>
                @error('gender') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field {{ $errors->has('date_of_birth') ? 'has-error' : '' }}">
                <label for="date_of_birth">
                    Date of birth <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <input id="date_of_birth" type="date" name="date_of_birth" required
                       value="{{ old('date_of_birth', $enrolee->date_of_birth?->format('Y-m-d')) }}">
                <p class="field-hint">Between 5 and 120 years old.</p>
                @error('date_of_birth') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field {{ $errors->has('marital_status') ? 'has-error' : '' }}">
                <label for="marital_status">
                    Marital status <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="marital_status" name="marital_status" required>
                    <option value="">Select marital status</option>
                    @foreach ($maritalStatuses as $status)
                        <option value="{{ $status['value'] }}"
                            @selected(old('marital_status', $enrolee->marital_status?->value) === $status['value'])>
                            {{ $status['label'] }}
                        </option>
                    @endforeach
                </select>
                @error('marital_status') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="field {{ $errors->has('education') ? 'has-error' : '' }}">
            <label for="education">
                Highest level of education <span class="required-marker" aria-hidden="true">*</span>
            </label>
            <select id="education" name="education" required>
                <option value="">Select level of education</option>
                @foreach ($educations as $education)
                    <option value="{{ $education['value'] }}"
                        @selected(old('education', $enrolee->education?->value) === $education['value'])>
                        {{ $education['label'] }}
                    </option>
                @endforeach
            </select>
            @error('education') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    <fieldset class="enrollee-form-section enrollee-form-section--contact">
        <legend>Contact and identification</legend>
        <p class="section-hint">How the enrollee can be reached and uniquely identified.</p>

        {{-- Full width on purpose: these two inputs are long, and the NIN counter
             needs room beside its hint. Half-width columns truncated on a phone. --}}
        <div class="field {{ $errors->has('phone') ? 'has-error' : '' }}">
            <label for="phone">
                Phone number <span class="required-marker" aria-hidden="true">*</span>
            </label>
            <input id="phone" type="tel" name="phone" autocomplete="tel" inputmode="tel"
                   maxlength="20" required
                   value="{{ old('phone', $enrolee->phone) }}">
            <p class="field-hint">
                Nigerian mobile, e.g. 08012345678 or +2348012345678.
            </p>
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('nin') ? 'has-error' : '' }}">
            <label for="nin">
                National Identification Number (NIN)
                <span class="required-marker" aria-hidden="true">*</span>
            </label>
            {{-- pattern is only a backstop: it catches the obvious mistakes
                 before a round trip. The regex in EnroleeRules is the authority. --}}
            <input id="nin" type="text" name="nin" inputmode="numeric" pattern="\d{11}"
                   maxlength="11" autocomplete="off" required
                   class="mono" data-nin-input aria-describedby="nin-hint"
                   value="{{ old('nin', $enrolee->nin) }}">
            <p class="field-hint" id="nin-hint">
                Exactly 11 digits, unique across the system.
                <span class="nin-counter" data-nin-counter
                      aria-live="polite">{{ strlen((string) old('nin', $enrolee->nin)) }}/11</span>
            </p>
            @error('nin') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field {{ $errors->has('address') ? 'has-error' : '' }}">
            <label for="address">
                Residential address <span class="required-marker" aria-hidden="true">*</span>
            </label>
            <textarea id="address" name="address" maxlength="300" required
                      rows="2">{{ old('address', $enrolee->address) }}</textarea>
            @error('address') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    <fieldset class="enrollee-form-section enrollee-form-section--location" data-cascade>
        <legend>Location</legend>

        <p class="field-hint">
            The ward and facility lists narrow as you choose. Both are re-checked
            when you save, so a mismatched combination cannot be stored.
        </p>

        <div class="grid grid-3 enrollee-form-grid">
            <div class="field {{ $errors->has('lga_id') ? 'has-error' : '' }}">
                <label for="lga_id">
                    Local Government Area <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="lga_id" name="lga_id" data-cascade="lga" required>
                    <option value="">Select an LGA</option>
                    @foreach ($lgas as $lga)
                        <option value="{{ $lga->id }}"
                            @selected((int) old('lga_id', $enrolee->lga_id) === $lga->id)>
                            {{ $lga->name }}
                        </option>
                    @endforeach
                </select>
                @error('lga_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            {{-- Populated by app.js from the LGA endpoint; the server renders
                 the record's current ward and facility so an edit page is
                 correct before any JavaScript runs. --}}
            <div class="field {{ $errors->has('ward_id') ? 'has-error' : '' }}">
                <label for="ward_id">
                    Ward <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="ward_id" name="ward_id" data-cascade="ward" required
                        @if (! $enrolee->ward) data-needs-parent="1" @endif>
                    @if ($enrolee->ward)
                        <option value="{{ $enrolee->ward->id }}" selected>{{ $enrolee->ward->name }}</option>
                    @else
                        <option value="">Select an LGA first</option>
                    @endif
                </select>
                @error('ward_id') <p class="field-error" data-error-for="ward_id">{{ $message }}</p> @enderror
            </div>

            <div class="field {{ $errors->has('facility_id') ? 'has-error' : '' }}">
                <label for="facility_id">
                    Health facility <span class="required-marker" aria-hidden="true">*</span>
                </label>
                <select id="facility_id" name="facility_id" data-cascade="facility" required
                        @if (! $enrolee->facility) data-needs-parent="1" @endif>
                    @if ($enrolee->facility)
                        <option value="{{ $enrolee->facility->id }}" selected>{{ $enrolee->facility->name }}</option>
                    @else
                        <option value="">Select a ward first</option>
                    @endif
                </select>
                @error('facility_id') <p class="field-error" data-error-for="facility_id">{{ $message }}</p> @enderror
            </div>
        </div>
    </fieldset>

    {{-- data-has-existing-photo is what makes "Remove / change photo" appear
     immediately on a record that already holds one, instead of waiting for the
     user to pick a new file. --}}
    <fieldset class="enrollee-form-section enrollee-form-section--photo"
              data-photo-picker @if ($enrolee->hasPhoto()) data-has-existing-photo @endif>
        <legend>
            Passport photograph
            <span class="required-marker" aria-hidden="true">*</span>
        </legend>

        <div class="field enrollee-photo-field {{ $errors->has('photo') ? 'has-error' : '' }}">
            <span class="field-label" id="photo-label">
                Photograph <span class="required-marker" aria-hidden="true">*</span>
            </span>

            {{-- The real input the form actually submits. It is visually hidden
                 rather than absent: app.js copies the chosen file into it through
                 DataTransfer, and if JavaScript is off or DataTransfer is
                 unavailable the input stays usable, so the form degrades to a
                 plain file field instead of silently dropping the photo. --}}
            <input id="photo" type="file" name="photo" accept="image/jpeg,image/png"
                   class="visually-hidden" data-photo-input
                   aria-labelledby="photo-label" aria-describedby="photo-hint photo-error">

            <div class="photo-actions">
                {{-- capture="user" asks for the front camera, which is what a
                     passport photograph needs. app.js removes this button on a
                     device with no camera. --}}
                <span class="photo-action" data-camera-wrapper>
                    <x-ui.button type="button" variant="secondary" data-photo-camera-trigger>
                        Take photo
                    </x-ui.button>
                </span>

                <span class="photo-action">
                    <x-ui.button type="button" variant="secondary" data-photo-file-trigger>
                        Choose from files
                    </x-ui.button>
                </span>

                {{-- The two inputs the buttons open. Neither is inside the form's
                     name space, so the browser cannot post them by accident. --}}
                <input type="file" accept="image/*" capture="user" class="visually-hidden"
                       tabindex="-1" aria-hidden="true"
                       data-photo-camera-input>
                <input type="file" accept="image/jpeg,image/png" class="visually-hidden"
                       tabindex="-1" aria-hidden="true"
                       data-photo-browser-input>
            </div>

            <p class="field-hint" id="photo-hint">
                JPG or PNG, 5&nbsp;MB maximum. Required in order to submit.
                @if ($enrolee->hasPhoto())
                    A photograph is already on file; uploading a new one replaces it.
                @endif
            </p>

            {{-- Always present, even when the server has not reported an error:
                 app.js writes client-side validation messages into this element.
                 It stays hidden and empty until there is something to say. --}}
            <p class="field-error enrollee-photo-error"
               id="photo-error"
               data-photo-error
               role="alert"
               aria-atomic="true"
               @if (! $errors->has('photo')) hidden @endif>{{ $errors->first('photo') }}</p>

            @if ($enrolee->hasPhoto())
                <div class="enrollee-current-photo">
                    <p class="enrollee-current-photo-label">Current photograph</p>
                    <div class="photo-frame mt-0">
                        <img src="{{ route('enrolees.photo', $enrolee) }}" alt="Current photograph">
                    </div>
                </div>
            @endif

            <div class="photo-preview" data-photo-preview></div>
        </div>
    </fieldset>

    <div class="form-actions enrollee-form-actions">
        {{-- Primary action. formaction sends these same fields to the submission
             endpoint, where every required field and the photograph are checked
             in full and the record is created already submitted. No
             formnovalidate: the browser's own checks run first, and app.js
             re-enables both buttons if the user cancels the confirmation. --}}
        @if ($canSubmit)
            <x-ui.button type="submit" variant="primary"
                         formaction="{{ $submitAction }}"
                         data-confirm-submit="Once submitted you cannot edit this record. Submit now?">
                <span data-submit-label>Submit registration</span>
            </x-ui.button>
        @endif

        <x-ui.button type="submit" variant="secondary" formnovalidate>
            <span data-submit-label>{{ $mode === 'create' ? 'Save draft' : 'Save changes' }}</span>
        </x-ui.button>

        @if ($mode === 'create')
            <x-ui.button :href="route('enrolees.index')" variant="link">Cancel</x-ui.button>
        @else
            <x-ui.button :href="route('enrolees.show', $enrolee)" variant="link">Cancel</x-ui.button>
        @endif
    </div>
</form>
