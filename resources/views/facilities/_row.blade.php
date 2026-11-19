<a href="{{ route('facilities.show', $f) }}" style="display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:space-between;padding:12px 4px;border-top:1px solid var(--line);text-decoration:none;color:inherit">
    <div style="min-width:0">
        <div style="font-weight:800;color:var(--navy)">{{ $f->reference_no }}</div>
        <div style="word-break:break-word">{{ $f->purpose }}</div>
        <div class="hint" style="margin:2px 0 0">
            {{ $f->requester?->name }} &middot; {{ $f->department?->name }} &middot; needed {{ $f->date_needed?->format('M j, Y') }}
        </div>
    </div>
    @include('facilities._status', ['status' => $f->status])
</a>