{{--
    Form field wrapper.

    Props:
      label    — visible label text (escaped).
      for      — id of the control. When present, a real <label for> is used.
      required — renders the existing required marker.
      hint     — optional hint text.
      error    — optional error text; adds .has-error to the wrapper.
      group    — when true, renders a non-label heading instead of <label for>.
                 Use this for button-driven groups such as the photograph picker.

    The control is the default slot. The caller must put aria-describedby on
    the control when hint/error text is present; a slot cannot be inspected to
    add it automatically.
--}}
@props([
    'label' => null,
    'for' => null,
    'required' => false,
    'hint' => null,
    'error' => null,
    'group' => false,
])

@php
    $hasError = $error !== null && $error !== '';
    $controlId = $for;
    $hintId = $controlId !== null ? $controlId . '-hint' : null;
    $errorId = $controlId !== null ? $controlId . '-error' : null;
    $classes = 'field' . ($hasError ? ' has-error' : '');
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($label !== null)
        @if ($controlId !== null && ! $group)
            <label for="{{ $controlId }}">
                {{ $label }}
                @if ($required)
                    <span class="required-marker" aria-hidden="true">*</span>
                @endif
            </label>
        @else
            <span class="field-label">
                {{ $label }}
                @if ($required)
                    <span class="required-marker" aria-hidden="true">*</span>
                @endif
            </span>
        @endif
    @endif

    {!! $slot !!}

    @if ($hint !== null)
        <p class="field-hint"@if ($hintId !== null) id="{{ $hintId }}"@endif>{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p class="field-error"@if ($errorId !== null) id="{{ $errorId }}"@endif>{{ $error }}</p>
    @endif
</div>
