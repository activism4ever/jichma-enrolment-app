{{-- Single flash-message partial, used by every view.

     Two success keys, deliberately not one. `status` is the ordinary "saved" or
     "done" message and can be flashed by any of a dozen controllers.
     `submitted` is reserved for the one-off moment a registration is locked, so
     the green "Registration submitted" banner can only be produced by a
     submission that has just happened — nothing else can put that wording on
     screen, and it lives for exactly one request, which is what makes "only
     right after submission" true rather than a thing to remember.

     The markup now comes from components/ui/alert.blade.php. The session keys,
     the two success banners and both ARIA roles are unchanged. --}}
@if (session('submitted'))
    <x-ui.alert variant="success" role="status" :message="session('submitted')" />
@endif

@if (session('status'))
    <x-ui.alert variant="success" role="status" :message="session('status')" />
@endif

@if (session('error'))
    <x-ui.alert variant="error" role="alert" :message="session('error')" />
@endif

@if ($errors->any())
    <x-ui.alert
        variant="error"
        role="alert"
        title="Please correct the following:"
        :messages="$errors->all()"
    />
@endif
