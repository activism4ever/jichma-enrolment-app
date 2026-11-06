@extends('layouts.app')

@section('title', 'Edit enrolee')

@section('content')
<div class="page-header">
        <h1>Edit {{ $enrolee->full_name ?: 'enrolee' }}</h1>
        <p class="page-subtitle">
            @if ($enrolee->isSubmitted())
                Submitted records are read-only for Program Users. You can still
                save a correction, and it will be recorded in the audit log.
            @else
                This is a draft. Save your changes to come back to it later, or
                submit it now — submitting checks every required field and
                requires a photograph, and locks the record afterwards.
            @endif
        </p>
    </div>

    @include('enrolees._form', ['mode' => 'edit', 'enrolee' => $enrolee])

    @can('delete', $enrolee)
        <div class="card">
            <h2 class="card-title">Delete this record</h2>
            <p class="text-muted">
                The record is removed from the working list but retained in the
                database for audit purposes. Its NIN stays reserved.
            </p>

            <form method="POST" action="{{ route('enrolees.destroy', $enrolee) }}"
                  data-confirm="Delete this record? It will no longer appear in the working list."
                  data-disable-on-submit>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <span data-submit-label>Delete record</span>
                </button>
            </form>
        </div>
    @endcan
@endsection
