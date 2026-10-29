{{-- LCCT pager. Overrides Laravel's built-in "pagination::tailwind" view, so every ->links() in the app uses it. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <div class="pager">
            @if ($paginator->onFirstPage())
                <span class="off" aria-disabled="true"><i class="fa-solid fa-chevron-left"></i> Prev</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="fa-solid fa-chevron-left"></i> Prev</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="gap" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="cur" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next <i class="fa-solid fa-chevron-right"></i></a>
            @else
                <span class="off" aria-disabled="true">Next <i class="fa-solid fa-chevron-right"></i></span>
            @endif
        </div>

        <p class="pager-info">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>
    </nav>
@endif
