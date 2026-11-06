{{--
    Numbered page links with the range actually on screen.

    Two things this replaces from the old Previous / "Page N of M" / Next bar:
    the page numbers themselves, so jumping from page 1 to page 12 does not
    take eleven presses; and the count of rows the links are moving between,
    which is the number people actually want ("Showing 1-25 of 1,248").

    Everything is a real link or a real current-page marker, so the whole bar
    is keyboard-reachable in order, and the current page carries aria-current.
    Disabled ends are spans rather than dead links, because a link that goes
    nowhere when followed is a worse thing to focus than a label that says
    nothing is there.

    Expects: $paginator (LengthAwarePaginator, already ->withQueryString()).
--}}
@if ($paginator->hasPages())
    @php
        // Three neighbours each side of the current page is enough to move
        // around a few hundred pages without turning the bar into a wall.
        $window = 3;
        $current = $paginator->currentPage();
        $lastPage = $paginator->lastPage();

        $pages = [];

        for ($page = 1; $page <= $lastPage; $page++) {
            if ($page === 1 || $page === $lastPage || abs($page - $current) <= $window) {
                $pages[] = $page;
            } elseif ($pages !== [] && end($pages) !== null) {
                // One ellipsis per gap: the check stops the following skipped
                // pages from stacking a second one.
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
