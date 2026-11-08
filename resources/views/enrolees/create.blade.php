@extends('layouts.app')

@section('title', 'New enrolee')

@section('content')
    <div class="enrollee-page enrollee-form-page">
        <x-ui.page-header
            title="New enrolee"
            subtitle="Save as a draft and finish later, or submit in one go: submitting checks every required field and requires a passport photograph, and locks the record afterwards."
        >
            <x-slot:breadcrumbs>
                <ol>
                    <li><a href="{{ route('enrolees.index') }}">Enrolees</a></li>
                    <li aria-current="page">New enrolee</li>
                </ol>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @include('enrolees._form', ['mode' => 'create', 'enrolee' => $enrolee])
    </div>
@endsection
