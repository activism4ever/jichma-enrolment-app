{{--
    Data table wrapper.

    Props:
      caption — optional accessible caption. It is rendered as a visually-hidden
                <caption>, so it is announced by screen readers without changing
                the visual design.

    The caller supplies <thead> and <tbody> in the slot and keeps every
    data-label, @can, @forelse and empty-state branch. Add scope="col" to each
    header cell in the caller until the table markup itself is migrated in a
    later phase.

    Attributes are forwarded to the <table> element, not to the scrolling
    .table-wrap container: id, class, aria-* and data-* describe the table
    itself. Any class supplied by the caller is merged with the default `data`
    class, so the wrapper class is never duplicated or overwritten.
--}}
@props([
    'caption' => null,
])

@php
    $tableAttributes = $attributes->merge(['class' => 'data']);
@endphp

<div class="table-wrap">
    <table {{ $tableAttributes }}>
        @if ($caption !== null)
            <caption class="visually-hidden">{{ $caption }}</caption>
        @endif

        {!! $slot !!}
    </table>
</div>
