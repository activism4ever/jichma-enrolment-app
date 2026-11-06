@extends('layouts.app')

@section('title', 'Edit programme')

@section('content')
    <div class="page-header">
        <h1>Edit {{ $program->code }}</h1>
        <p class="page-subtitle">{{ $program->name }}</p>
    </div>

    @include('admin.programs._form', ['mode' => 'edit', 'program' => $program])
@endsection
