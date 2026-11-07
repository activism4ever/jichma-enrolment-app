{{--
    Filter form wrapper.

    Props:
      action — form action URL. Omit to submit to the current URL.
      method — HTTP method; defaults to GET. This component does not add CSRF:
               a non-GET filter form must put @csrf in its own slot, and a
               filter form should stay GET unless a controller explicitly
               expects otherwise.

    Slots:
      $slot — the .field children.
      chips — optional active-filter chips. They span the full grid width.

    Preserves every field name, id, value and data-* attribute supplied by the
    caller. The existing .filters styling is reused unchanged.
--}}
@props([
    'action' => null,
    'method' => 'GET',
])

@php
    $hasChips = isset($chips) && trim((string) $chips) !== '';
    $formMethod = strtoupper($method) === 'POST' ? 'POST' : 'GET';
@endphp

<form method="{{ $formMethod }}"@if ($action !== null) action="{{ $action }}"@endif {{ $attributes->merge(['class' => 'filters card']) }}>
    @if ($hasChips)
        <div class="filter-chips" role="group" aria-label="Active filters">
            {!! $chips !!}
        </div>
    @endif

    {!! $slot !!}
</form>
