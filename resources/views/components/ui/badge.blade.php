{{--
    Status badge.

    Props:
      variant — draft, submitted, active, inactive, sensitive, or neutral.

    The text is always rendered; colour is never the only carrier of meaning.
--}}
@props([
    'variant' => 'neutral',
])

@php
    $allowed = ['draft', 'submitted', 'active', 'inactive', 'sensitive', 'neutral'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'neutral';
@endphp

<span {{ $attributes->merge(['class' => 'badge badge-' . $variant]) }}>{{ $slot }}</span>
