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

    The implementation now lives in components/ui/pagination.blade.php so the
    markup can be reused without changing this include's contract. --}}
<x-ui.pagination :paginator="$paginator" />
