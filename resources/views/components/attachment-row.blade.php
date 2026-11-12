@props(['file', 'ticket', 'detail' => true])

{{--
    One uploaded file. The name opens the pop-up viewer (images, PDF, Word text) with previous / next and zoom;
    without JavaScript it is an ordinary link to the authorized route. "Remove" only shows to people the
    RequestAttachmentPolicy allows (the server checks again). Pass :detail="false" for the short line used in replies.
--}}
@php
    $docx = $file->mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $kind = $file->isImage() ? 'image' : ($file->isPdf() ? 'pdf' : ($docx ? 'docx' : 'other'));
    $file->setRelation('request', $ticket);   // saves one query per file in the policy check
    $canDelete = auth()->user()?->can('delete', $file) ?? false;
    $meta = $detail
        ? $file->sizeForHumans().' · '.($file->uploader?->name ?? 'unknown').' · '.$file->created_at?->format('M j, Y g:i A')
        : $file->sizeForHumans();
@endphp

<div {{ $attributes->merge(['class' => 'pf-row', 'style' => 'flex-wrap:wrap']) }}>
    <div style="min-width:0;flex:1">
        <a href="{{ $file->url() }}" @if ($file->isInline()) target="_blank" rel="noopener" @endif style="word-break:break-all"
           data-file-view
           data-kind="{{ $kind }}"
           data-name="{{ $file->original_name }}"
           data-src="{{ $file->url() }}"
           data-download="{{ $file->downloadUrl() }}"
           @if ($docx) data-text-url="{{ route('attachments.text', $file) }}" @endif
           @if ($canDelete) data-delete-url="{{ route('attachments.destroy', $file) }}" @endif
           data-meta="{{ $meta }}">
            <span aria-hidden="true">{{ $file->icon() }}</span> {{ $file->original_name }}
        </a>
        <div class="sub" style="font-size:12.5px;color:var(--text-3)">
            {{ $meta }}
            @if ($detail && ! $file->isRequestFile())
                <span class="badge pr-normal" style="margin-left:4px">{{ $file->kindLabel() }}</span>
            @endif
        </div>
    </div>

    <a href="{{ $file->downloadUrl() }}" class="btn btn-sm"><i class="fa-solid fa-download"></i> Download</a>

    @if ($canDelete)
        <form method="POST" action="{{ route('attachments.destroy', $file) }}" style="display:inline"
              onsubmit="return confirm({{ \Illuminate\Support\Js::from('Remove “'.$file->original_name.'” from this ticket? This cannot be undone. The removal is recorded in the audit log.') }})">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger"><i class="fa-regular fa-trash-can"></i> Remove</button>
        </form>
    @endif
</div>
