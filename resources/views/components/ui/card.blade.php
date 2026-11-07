{{--
    Card surface.

    Props:
      title       — optional card heading (escaped).
      description — optional supporting line under the heading (escaped).

    Slots:
      actions — optional controls aligned to the right of the heading.
      $slot   — card body markup.

    The caller owns all data access and authorisation. This component only
    supplies the existing .card surface and a consistent heading row.
--}}
@props([
    'title' => null,
    'description' => null,
])

@php
    $hasActions = isset($actions) && trim((string) $actions) !== '';
    $hasHeading = $title !== null || $description !== null || $hasActions;
@endphp

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($hasHeading)
        <div class="card-head">
            <div class="card-heading">
                @if ($title !== null)
                    <h2 class="card-title">{{ $title }}</h2>
                @endif

                @if ($description !== null)
                    <p class="card-description text-muted">{{ $description }}</p>
                @endif
            </div>

            @if ($hasActions)
                <div class="card-actions">
                    {!! $actions !!}
                </div>
            @endif
        </div>
    @endif

    {!! $slot !!}
</section>
