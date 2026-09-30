{{-- A list's pages, as DataTables shows them: which rows these are, then Previous, the page numbers around this one
     with the first and last, and Next. For a paginator ($paginator), named for screen readers by $label, like
     "Pages of figures". On Crime data and Police stations. --}}
<nav class="pager" aria-label="{{ $label }}">
    <span class="muted">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}</span>
    @if ($paginator->onFirstPage())
        <span class="btn btn-sm is-disabled" aria-disabled="true">Previous</span>
    @else
        <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
    @endif
    @if ($paginator->hasPages())
        @foreach ($paginator->onEachSide(1)->linkCollection()->slice(1, -1) as $link)
            @if ($link['url'] === null)
                <span class="pager-gap" aria-hidden="true">…</span>
            @elseif ($link['active'])
                <span class="btn btn-sm pager-current" aria-current="page"><span class="visually-hidden">Page </span>{{ $link['label'] }}</span>
            @else
                <a class="btn btn-sm pager-page" href="{{ $link['url'] }}"><span class="visually-hidden">Page </span>{{ $link['label'] }}</a>
            @endif
        @endforeach
    @endif
    @if ($paginator->hasMorePages())
        <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
    @else
        <span class="btn btn-sm is-disabled" aria-disabled="true">Next</span>
    @endif
</nav>
