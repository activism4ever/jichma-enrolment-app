{{-- Single flash-message component, used by every view.

     Two success keys, deliberately not one. `status` is the ordinary "saved" or
     "done" message and can be flashed by any of a dozen controllers.
     `submitted` is reserved for the one-off moment a registration is locked, so
     the green "Registration submitted" banner can only be produced by a
     submission that has just happened — nothing else can put that wording on
     screen, and it lives for exactly one request, which is what makes "only
     right after submission" true rather than a thing to remember. --}}
@if (session('submitted'))
    <div class="alert alert-success" role="status">{{ session('submitted') }}</div>
@endif

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="alert alert-error" role="alert">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <p class="alert-title">Please correct the following:</p>
        <ul class="alert-list">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif