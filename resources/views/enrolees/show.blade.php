@extends('layouts.app')

@section('title', $enrolee->full_name ?: 'Enrolee')

@section('content')
    <div class="enrollee-page enrollee-record">
        <header class="page-header enrollee-record-header">
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <ol>
                    <li><a href="{{ route('enrolees.index') }}">Enrolees</a></li>
                    <li aria-current="page">{{ $enrolee->full_name ?: 'Enrolee' }}</li>
                </ol>
            </nav>

            <div class="record-header-row">
                <div class="record-heading">
                    <h1>{{ $enrolee->full_name ?: 'Unnamed enrolee' }}</h1>

                    <p class="record-meta">
                        <x-ui.badge :variant="$enrolee->status->value">{{ $enrolee->status->label() }}</x-ui.badge>

                        @if ($enrolee->program?->isSensitive())
                            <x-ui.badge variant="sensitive">Sensitive: {{ $enrolee->program->code }}</x-ui.badge>
                        @endif

                        <span class="record-meta-text text-muted">
                            Registered {{ $enrolee->created_at?->format('j M Y') }}
                            @if ($enrolee->isSubmitted())
                                <span class="record-meta-separator" aria-hidden="true">&middot;</span>
                                submitted {{ $enrolee->submitted_at?->format('j M Y, H:i') }}
                            @endif
                        </span>
                    </p>
                </div>

                <div class="page-header-actions enrollee-record-header-actions">
                    <x-ui.button :href="route('enrolees.index')" variant="secondary">Back to list</x-ui.button>

                    @if ($canEdit)
                        <x-ui.button :href="route('enrolees.edit', $enrolee)" variant="primary">Edit</x-ui.button>
                    @endif

                    @if ($canSubmit && $enrolee->isDraft())
                        <form method="POST" action="{{ route('enrolees.submit', $enrolee) }}"
                              enctype="multipart/form-data" class="inline-form"
                              data-disable-on-submit>
                            @csrf
                            <x-ui.button type="submit" variant="primary">
                                <span data-submit-label>Submit registration</span>
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </header>

        @if ($enrolee->isSubmitted() && ! $canEdit && ! session('submitted'))
            {{-- Suppressed on the one request that carries the green
                 "Registration submitted" banner: straight after submitting, the
                 record is locked *because* of what the user has just done, and
                 telling them so in two banners at once reads as a problem. On every
                 later visit the flash is gone and this is the notice that remains. --}}
            <x-ui.alert variant="info">
                This record is locked. It was submitted
                {{ $enrolee->submitted_at?->format('j M Y, H:i') }}
                @if ($enrolee->submitter)
                    by {{ $enrolee->submitter->name }}
                @endif
                and can no longer be edited by you.
            </x-ui.alert>
        @endif

        @if ($enrolee->program?->isSensitive())
            <x-ui.alert variant="warning">
                This record belongs to a sensitive program. NIN and phone numbers are
                masked in lists and every access is written to the audit log.
            </x-ui.alert>
        @endif

        <div class="grid grid-2 enrollee-record-grid">
            <div class="record-column">
                <x-ui.card title="Personal details" description="Name and personal information.">
                    <dl class="definition-list">
                        <dt>Surname</dt>
                        <dd>{{ $enrolee->surname ?: '—' }}</dd>

                        <dt>First name</dt>
                        <dd>{{ $enrolee->first_name ?: '—' }}</dd>

                        <dt>Middle name</dt>
                        <dd>{{ $enrolee->middle_name ?: '—' }}</dd>

                        <dt>Gender</dt>
                        <dd>{{ $enrolee->gender?->label() ?? '—' }}</dd>

                        <dt>Date of birth</dt>
                        <dd>
                            {{ $enrolee->date_of_birth?->format('j M Y') ?? '—' }}
                            @if ($enrolee->ageInYears() !== null)
                                <span class="text-muted">({{ $enrolee->ageInYears() }} years)</span>
                            @endif
                        </dd>

                        <dt>Marital status</dt>
                        <dd>{{ $enrolee->marital_status?->label() ?? '—' }}</dd>

                        <dt>Education</dt>
                        <dd>{{ $enrolee->education?->label() ?? '—' }}</dd>
                    </dl>
                </x-ui.card>

                <x-ui.card title="Contact and identification" description="How this enrollee can be reached and identified.">
                    <dl class="definition-list">
                        <dt>Phone</dt>
                        <dd class="mono">{{ $enrolee->phone ?: '—' }}</dd>

                        <dt>NIN</dt>
                        <dd class="mono">{{ $enrolee->nin ?: '—' }}</dd>

                        <dt>Address</dt>
                        <dd>{{ $enrolee->address ?: '—' }}</dd>
                    </dl>
                </x-ui.card>
            </div>

            <div class="record-column">
                <x-ui.card title="Photograph">
                    @if ($enrolee->hasPhoto())
                        {{-- A button rather than a bare image, so the photograph can
                             be enlarged without adding a second, unguarded URL for
                             the full-size copy: the lightbox reads its src from the
                             image already below, which is the authorised
                             enrolees.photo route, and that route re-checks
                             EnroleePolicy::view on every request.

                             With JavaScript unavailable this is simply a box holding
                             a photograph, so nothing is hidden behind a click. --}}
                        <button type="button" class="photo-frame photo-zoom"
                                data-lightbox aria-haspopup="dialog"
                                aria-label="Enlarge the passport photograph">
                            <img src="{{ route('enrolees.photo', $enrolee) }}"
                                 alt="Passport photograph of {{ $enrolee->full_name ?: 'this enrolee' }}">
                        </button>
                        <p class="photo-hint">Select the photograph to enlarge it.</p>
                    @else
                        <div class="photo-frame photo-missing">No photograph on file</div>
                    @endif
                </x-ui.card>

                <x-ui.card title="Registration" description="Programme, location and audit details.">
                    <dl class="definition-list">
                        <dt>Program</dt>
                        <dd>{{ $enrolee->program?->code ?? '—' }}</dd>

                        <dt>Registered by</dt>
                        <dd>{{ $enrolee->creator?->name ?? '—' }}</dd>

                        <dt>Submitted by</dt>
                        <dd>{{ $enrolee->submitter?->name ?? '—' }}</dd>

                        <dt>LGA</dt>
                        <dd>{{ $enrolee->lga?->name ?? '—' }}</dd>

                        <dt>Ward</dt>
                        <dd>{{ $enrolee->ward?->name ?? '—' }}</dd>

                        <dt>Health facility</dt>
                        <dd>{{ $enrolee->facility?->name ?? '—' }}</dd>
                    </dl>
                </x-ui.card>
            </div>
        </div>

        @if ($canDelete)
            <x-ui.card
                class="enrollee-danger-zone"
                title="Delete this record"
                description="The record is removed from the working list but retained in the database for audit purposes."
            >
                <form method="POST" action="{{ route('enrolees.destroy', $enrolee) }}"
                      class="inline-form"
                      data-confirm="Delete this record? It will no longer appear in the working list."
                      data-disable-on-submit>
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <span data-submit-label>Delete record</span>
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
