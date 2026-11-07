{{--
    Button or button-styled link.

    Props:
      href    — when present, renders an <a>; otherwise renders a <button>.
      variant — primary, secondary, danger, or link.
      size    — null, "sm", or "lg".
      block   — when true, adds .btn-block.
      type    — button type for the <button> form. Defaults to "button" so a
                component added inside a form cannot submit it by accident.

    Slots:
      $slot — button text or markup.

    The caller keeps data-disable-on-submit, data-confirm, formaction, CSRF and
    every other behavioural attribute by passing them through; they survive via
    $attributes->merge().
--}}
@props([
    'href' => null,
    'variant' => 'primary',
    'size' => null,
    'block' => false,
    'type' => 'button',
])

@php
    $variants = ['primary', 'secondary', 'danger', 'link'];
    $variant = in_array($variant, $variants, true) ? $variant : 'secondary';

    $classes = 'btn btn-' . $variant;
    if ($size === 'sm') {
        $classes .= ' btn-sm';
    } elseif ($size === 'lg') {
        $classes .= ' btn-lg';
    }
    if ($block) {
        $classes .= ' btn-block';
    }
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{!! $slot !!}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{!! $slot !!}</button>
@endif
