@extends('layouts.app')

@section('title', 'New enrolee')

@section('content')
    <div class="page-header">
        <h1>New enrolee</h1>
        <p class="page-subtitle">
            Save as a draft and finish later, or submit in one go: submitting
            checks every required field and requires a passport photograph, and
            locks the record afterwards.
        </p>
    </div>

    @include('enrolees._form', ['mode' => 'create', 'enrolee' => $enrolee])
@endsection
