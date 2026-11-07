{{--
    Page header.

    Props:
      title       — heading text (escaped). Leave null to use the default slot.
      subtitle    — supporting sentence (escaped).
      heading     — "h1" (default) or "h2".

    Slots:
      actions     — optional primary/secondary actions, rendered beside the title.
      breadcrumbs — optional breadcrumb markup, rendered above the title.
      $slot       — fallback heading content when title is null.

    The component is presentational only. Views keep their own @can gates and
    route() calls inside the slots.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'heading' => 'h1',
])

@php
    $hasActions = isset($actions) && trim((string) $actions) !== '';
    $hasBreadcrumbs = isset($breadcrumbs) && trim((string) $breadcrumbs) !== '';
    $headingTag = $heading === 'h2' ? 'h2' : 'h1';
    $classes = 'page-header' . ($hasActions ? ' page-header--actions' : '');
@endphp

<header {{ $attributes->merge(['class' => $classes]) }}>
    @if ($hasBreadcrumbs)
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            {!! $breadcrumbs !!}
        </nav>
    @endif

    @if ($title !== null)
        @if ($headingTag === 'h2')
            <h2>{{ $title }}</h2>
        @else
            <h1>{{ $title }}</h1>
        @endif
    @else
        {!! $slot !!}
    @endif

    @if ($hasActions)
        <div class="page-header-actions">
            {!! $actions !!}
        </div>
    @endif

    @if ($subtitle !== null)
        <p class="page-subtitle">{{ $subtitle }}</p>
    @endif
</header>
