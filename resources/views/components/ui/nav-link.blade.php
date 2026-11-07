{{--
    Primary navigation link.

    Props:
      href   — destination URL.
      active — when true, renders aria-current="page" and .is-active.

    The caller computes "active" from the current route, for example:
      :active="request()->routeIs('enrolees.*')"
    so the component needs no request access of its own.
--}}
@props([
    'href' => null,
    'active' => false,
])

@php
    $classes = $active ? 'is-active' : '';
@endphp

<a href="{{ $href }}"@if ($active) aria-current="page"@endif {{ $attributes->merge(['class' => $classes]) }}>{!! $slot !!}</a>
