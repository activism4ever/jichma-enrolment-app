{{--
    Numbered pagination.

    Expects $paginator, a LengthAwarePaginator that has already been called
    with ->withQueryString() by the controller.

    Behaviour is unchanged from partials/pagination.blade.php: real links for
    every reachable page, a span for the current page with aria-current, spans
    (not dead links) for the disabled ends, and one ellipsis per gap.
--}}
@props([
    'paginator',
])

@if ($paginator->hasPages())
    @php
        $window = 3;
        $current = $paginator->currentPage();
        $lastPage = $paginator->lastPage();

        $pages = [];

        for ($page = 1; $page <= $lastPage; $page++) {
            if ($page === 1 || $page === $lastPage || abs($page - $current) <= $window) {
                $pages[] = $page;
            } elseif ($pages !== [] && end($pages) !== null) {
                $pages[] = null;
            }
        }

        // Null when the requested page is past the end of the list.
        $first = $paginator->firstItem() ?? 0;
        $last = $paginator->lastItem() ?? 0;
    @endphp

    <nav class="pagination" aria-label="Pagination">
        <p class="pagination-summary">
            Showing {{ number_format($first) }}–{{ number_format($last) }} of {{ number_format($paginator->total()) }}
        </p>

        <ul class="pagination-pages">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="is-disabled" aria-disabled="true">Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                @endif
            </li>

            @foreach ($pages as $page)
                <li>
                    @if ($page === null)
                        <span class="is-ellipsis" aria-hidden="true">&hellip;</span>
                    @elseif ($page === $current)
                        <span class="is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $paginator->url($page) }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                </li>
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
                @else
                    <span class="is-disabled" aria-disabled="true">Next</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
