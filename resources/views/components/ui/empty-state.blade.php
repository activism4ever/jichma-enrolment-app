{{--
    Empty-state block.

    Props:
      title — optional short heading (escaped).

    Slots:
      $slot   — explanation and any inline content.
      actions — optional action row under the explanation.

    The caller decides which absence is being described. Do not use this
    component to hide an authorisation failure.
--}}
@props([
    'title' => null,
])

@php
    $hasActions = isset($actions) && trim((string) $actions) !== '';
@endphp

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    @if ($title !== null)
        <p><strong>{{ $title }}</strong></p>
    @endif

    {!! $slot !!}

    @if ($hasActions)
        <p class="empty-state-actions">
            {!! $actions !!}
        </p>
    @endif
</div>
