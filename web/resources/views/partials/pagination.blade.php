@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pages">
        @if ($paginator->onFirstPage())<span class="disabled">‹ Prev</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>@endif
        <span class="current">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>@else<span class="disabled">Next ›</span>@endif
    </nav>
@endif
