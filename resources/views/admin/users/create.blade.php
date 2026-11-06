@extends('layouts.app')

@section('title', 'New user')

@section('content')
    <div class="page-header">
        <h1>New user</h1>
        <p class="page-subtitle">Create a Program User or Reviewer account.</p>
    </div>

    {{-- The program field appears only for roles that belong to one; the
         toggle lives in public/js/app.js because the Content-Security-Policy
         forbids inline script. --}}
    @include('admin.users._form', ['mode' => 'create', 'user' => new \App\Models\User])
@endsection
