@props(['ticket'])

{{-- EMERGENCY / CONFIDENTIAL badges, shown next to the status badge --}}
@if ($ticket->is_emergency)
    <span class="inline-block rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-extrabold uppercase text-white">🚨 Emergency</span>
@endif
@if ($ticket->is_confidential)
    <span class="inline-block rounded-full bg-gray-800 px-2.5 py-0.5 text-xs font-extrabold uppercase text-white">🔒 Confidential</span>
@endif
