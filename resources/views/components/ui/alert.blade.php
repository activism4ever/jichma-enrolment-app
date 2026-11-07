{{--
    Feedback alert.

    Props:
      variant  — success, error, info, or warning.
      title    — optional heading (escaped).
      message  — optional single message (escaped).
      messages — optional array of messages, rendered as a list (each escaped).
      role     — optional ARIA role. Defaults to "alert" for errors and
                 "status" for everything else.

    The default slot is rendered as Blade markup. Use message/messages for data
    that comes from a controller or session; only pass trusted, already-escaped
    content through the slot.
--}}
@props([
    'variant' => 'info',
    'title' => null,
    'message' => null,
    'messages' => [],
    'role' => null,
])

@php
    $allowed = ['success', 'error', 'info', 'warning'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'info';
    $messages = is_array($messages) ? $messages : [];
    $role = $role ?? ($variant === 'error' ? 'alert' : 'status');
    $hasSlot = trim((string) $slot) !== '';
@endphp

<div {{ $attributes->merge(['class' => 'alert alert-' . $variant]) }}@if ($role !== null) role="{{ $role }}"@endif>
    @if ($title !== null)
        <p class="alert-title">{{ $title }}</p>
    @endif

    @if ($message !== null)
        <p class="alert-message">{{ $message }}</p>
    @elseif ($messages !== [])
        <ul class="alert-list">
            @foreach ($messages as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @elseif ($hasSlot)
        {{ $slot }}
    @endif
</div>
