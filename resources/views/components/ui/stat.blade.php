{{--
    One figure and its label.

    Props:
      value — the number or short value to display (escaped).
      label — the description under it (escaped).

    Wrap a group of these in a .stat-row, or .stat-row-3 for exactly three.
--}}
@props([
    'value' => null,
    'label' => null,
])

<div {{ $attributes->merge(['class' => 'stat']) }}>
    <div class="stat-value">{{ $value }}</div>

    @if ($label !== null)
        <div class="stat-label">{{ $label }}</div>
    @endif
</div>
