@extends('layouts.app')

@section('title', 'New programme')

@section('content')
    <div class="page-header">
        <h1>New programme</h1>
        <p class="page-subtitle">Create a programme for enrolments, accounts and exports.</p>
    </div>

    @include('admin.programs._form', ['mode' => 'create', 'program' => $program])
@endsection
