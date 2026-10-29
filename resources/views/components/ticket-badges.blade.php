@props(['ticket'])

{{-- EMERGENCY / CONFIDENTIAL / DUE badges, shown next to the status badge --}}
@if ($ticket->is_emergency)
    <span class="badge badge-solid-red"><i class="fa-solid fa-triangle-exclamation"></i> Emergency</span>
@endif
@if ($ticket->is_confidential)
    <span class="badge badge-solid-dark"><i class="fa-solid fa-lock"></i> Confidential</span>
@endif
<x-due-badge :ticket="$ticket" />
