@extends('layouts.app')

@section('title', $enrolee->full_name ?: 'Enrolee')

@section('content')
    <div class="page-header">
        <h1>{{ $enrolee->full_name ?: 'Unnamed enrolee' }}</h1>
        <p class="page-subtitle">
            <span class="badge badge-{{ $enrolee->status->value }}">{{ $enrolee->status->label() }}</span>
            @if ($enrolee->program?->isSensitive())
                <span class="badge badge-sensitive">Sensitive: {{ $enrolee->program->code }}</span>
            @endif
            <span class="text-muted">
                Registered {{ $enrolee->created_at?->format('j M Y') }}
                @if ($enrolee->isSubmitted())
                    &middot; submitted {{ $enrolee->submitted_at?->format('j M Y, H:i') }}
                @endif
            </span>
        </p>
    </div>

    @if ($enrolee->isSubmitted() && ! $canEdit && ! session('submitted'))
        {{-- Suppressed on the one request that carries the green
             "Registration submitted" banner: straight after submitting, the
             record is locked *because* of what the user has just done, and
             telling them so in two banners at once reads as a problem. On every
             later visit the flash is gone and this is the notice that remains. --}}
        <div class="alert alert-info">
            This record is locked. It was submitted
            {{ $enrolee->submitted_at?->format('j M Y, H:i') }}
            @if ($enrolee->submitter)
                by {{ $enrolee->submitter->name }}
            @endif
            and can no longer be edited by you.
        </div>
    @endif

    @if ($enrolee->program?->isSensitive())
        <div class="alert alert-warning">
            This record belongs to a sensitive program. NIN and phone numbers are
            masked in lists and every access is written to the audit log.
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h2 class="card-title">Personal details</h2>
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

                <dt>Phone</dt>
                <dd class="mono">{{ $enrolee->phone ?: '—' }}</dd>

                <dt>NIN</dt>
                <dd class="mono">{{ $enrolee->nin ?: '—' }}</dd>

                <dt>Address</dt>
                <dd>{{ $enrolee->address ?: '—' }}</dd>
            </dl>
        </div>

        <div>
            <div class="card">
                <h2 class="card-title">Photograph</h2>

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
            </div>

            <div class="card">
                <h2 class="card-title">Registration</h2>
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
            </div>
        </div>
    </div>

    <div class="toolbar">
        <div class="inline-list">
            <a href="{{ route('enrolees.index') }}" class="btn btn-secondary">Back to list</a>

            @if ($canEdit)
                <a href="{{ route('enrolees.edit', $enrolee) }}" class="btn btn-primary">Edit</a>
            @endif

            @if ($canSubmit && $enrolee->isDraft())
                <form method="POST" action="{{ route('enrolees.submit', $enrolee) }}"
                      enctype="multipart/form-data" class="inline-form"
                      data-disable-on-submit>
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <span data-submit-label>Submit registration</span>
                    </button>
                </form>
            @endif
        </div>

        @if ($canDelete)
            <form method="POST" action="{{ route('enrolees.destroy', $enrolee) }}"
                  class="inline-form"
                  data-confirm="Delete this record? It will no longer appear in the working list."
                  data-disable-on-submit>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <span data-submit-label>Delete</span>
                </button>
            </form>
        @endif
    </div>
@endsection
