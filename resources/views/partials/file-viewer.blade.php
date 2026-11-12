{{--
    Pop-up file viewer: every link that has data-file-view (see components/attachment-row) opens here.
    Image / PDF / Word text, previous & next, zoom, "New tab", Download and (when allowed) Remove.
    Plain files in /public (no build step): css/file-viewer.css and js/file-viewer.js.
    Include once per page, inside @push('scripts') or at the end of the page.
--}}
<link rel="stylesheet" href="{{ asset('css/file-viewer.css') }}?v={{ @filemtime(public_path('css/file-viewer.css')) }}">

<dialog id="file-viewer" class="fv" aria-labelledby="fv-title">
    <div class="fv-head">
        <div class="fv-title">
            <strong id="fv-title" data-fv-title></strong>
            <small data-fv-meta></small>
        </div>
        <div class="fv-actions">
            <button type="button" class="fv-btn" data-fv-prev aria-label="Previous file" title="Previous (←)"><i class="fa-solid fa-chevron-left"></i></button>
            <span class="fv-count" data-fv-count aria-live="polite"></span>
            <button type="button" class="fv-btn" data-fv-next aria-label="Next file" title="Next (→)"><i class="fa-solid fa-chevron-right"></i></button>

            <span class="fv-zoom" data-fv-zoom hidden>
                <button type="button" class="fv-btn" data-fv-zoom-out aria-label="Zoom out" title="Zoom out (−)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" class="fv-level" data-fv-zoom-reset aria-label="Reset zoom" title="Reset zoom (0)">100%</button>
                <button type="button" class="fv-btn" data-fv-zoom-in aria-label="Zoom in" title="Zoom in (+)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </span>

            <a class="btn btn-sm" data-fv-open target="_blank" rel="noopener" hidden><i class="fa-solid fa-arrow-up-right-from-square"></i> New tab</a>
            <a class="btn btn-sm btn-primary" data-fv-download hidden><i class="fa-solid fa-download"></i> Download</a>
            <button type="button" class="btn btn-sm btn-danger" data-fv-delete hidden><i class="fa-regular fa-trash-can"></i> Remove</button>
            <button type="button" class="fv-btn" data-fv-close aria-label="Close" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>
    <div class="fv-body" data-fv-body></div>
</dialog>

<script src="{{ asset('js/file-viewer.js') }}?v={{ @filemtime(public_path('js/file-viewer.js')) }}" defer></script>
