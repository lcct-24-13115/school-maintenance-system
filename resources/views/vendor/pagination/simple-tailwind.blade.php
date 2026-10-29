{{-- LCCT pager for simplePaginate(). Overrides "pagination::simple-tailwind". --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <div class="pager">
            @if ($paginator->onFirstPage())
                <span class="off" aria-disabled="true"><i class="fa-solid fa-chevron-left"></i> Prev</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="fa-solid fa-chevron-left"></i> Prev</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next <i class="fa-solid fa-chevron-right"></i></a>
            @else
                <span class="off" aria-disabled="true">Next <i class="fa-solid fa-chevron-right"></i></span>
            @endif
        </div>
    </nav>
@endif
