@props(['status'])

{{-- Phase 18: the colour comes from TicketStatus::badgeClass() (one colour per status, public/css/phase6.css). --}}
<span {{ $attributes->merge(['class' => 'badge '.$status->badgeClass()]) }}>{{ $status->label() }}</span>
