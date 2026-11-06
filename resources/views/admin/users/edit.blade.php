@extends('layouts.app')

@section('title', 'Edit user')

@section('content')
    <div class="page-header">
        <h1>Edit {{ $user->name }}</h1>
        <p class="page-subtitle">{{ $user->email }}</p>
    </div>

    @include('admin.users._form', ['mode' => 'edit', 'user' => $user])
@endsection
