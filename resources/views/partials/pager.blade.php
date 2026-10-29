{{--
    Phase 5 (design): LCCT pager (.pager) for any paginator.   Usage: @include('partials.pager', ['paginator' => $tickets])
    Links keep whatever query string the controller added with withQueryString().
--}}
@if ($paginator->hasPages())
    @php
        $cur = $paginator->currentPage();
        $last = $paginator->lastPage();
        $pages = collect([1, $last])->merge(range(max(1, $cur - 2), min($last, $cur + 2)))->unique()->sort()->values();
        $prev = null;
    @endphp
    <nav class="pager" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span style="opacity:.5">&lsaquo; Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Prev</a>
        @endif

        @foreach ($pages as $p)
            @if ($prev !== null && $p - $prev > 1)<span>…</span>@endif
            @if ($p === $cur)
                <span class="cur" aria-current="page">{{ $p }}</span>
            @else
                <a href="{{ $paginator->url($p) }}">{{ $p }}</a>
            @endif
            @php $prev = $p; @endphp
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &rsaquo;</a>
        @else
            <span style="opacity:.5">Next &rsaquo;</span>
        @endif
    </nav>
@endif
