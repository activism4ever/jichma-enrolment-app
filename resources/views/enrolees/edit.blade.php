@extends('layouts.app')

@section('title', 'Edit enrolee')

@section('content')
    @php
        $editDescription = $enrolee->isSubmitted()
            ? 'Submitted records are read-only for Program Users. You can still save a correction, and it will be recorded in the audit log.'
            : 'This is a draft. Save your changes to come back to it later, or submit it now — submitting checks every required field and requires a photograph, and locks the record afterwards.';
    @endphp

    <div class="enrollee-page enrollee-form-page">
        <x-ui.page-header
            :title="'Edit '.($enrolee->full_name ?: 'enrolee')"
            :subtitle="$editDescription"
        >
            <x-slot:breadcrumbs>
                <ol>
                    <li><a href="{{ route('enrolees.index') }}">Enrolees</a></li>
                    <li><a href="{{ route('enrolees.show', $enrolee) }}">{{ $enrolee->full_name ?: 'Enrolee' }}</a></li>
                    <li aria-current="page">Edit</li>
                </ol>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @include('enrolees._form', ['mode' => 'edit', 'enrolee' => $enrolee])

        @can('delete', $enrolee)
            <x-ui.card
                class="enrollee-danger-zone"
                title="Delete this record"
                description="The record is removed from the working list but retained in the database for audit purposes. Its NIN stays reserved."
            >
                <form method="POST" action="{{ route('enrolees.destroy', $enrolee) }}"
                      data-confirm="Delete this record? It will no longer appear in the working list."
                      data-disable-on-submit>
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <span data-submit-label>Delete record</span>
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endcan
    </div>
@endsection
