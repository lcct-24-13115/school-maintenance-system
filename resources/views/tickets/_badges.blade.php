{{--
    Phase 4 (design): status + EMERGENCY + CONFIDENTIAL + DUE badges in the LCCT style (.badge / .st-* / .pr-*).
    Same rules as <x-status-badge>, <x-ticket-badges> and <x-due-badge>, which are left untouched because the
    pages that are not redesigned yet still use them.
    Usage: @include('tickets._badges', ['ticket' => $ticket])
--}}
@php
    // Phase 18: one colour per status, decided by TicketStatus::badgeClass()

    // "Due in ..." / "Overdue by ...": only for tickets that are still pending
    $paused = $ticket->status === \App\Enums\TicketStatus::WaitingForUser;   // Phase 9: SLA clock paused
    $showDue = $ticket->due_at !== null && $ticket->status->isPending() && ! $paused;
    $isOverdue = $showDue && $ticket->due_at->isPast();
    $dueSpan = $showDue ? $ticket->due_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2) : '';
@endphp
<span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span>
@if ($ticket->isPmGenerated())
    <span class="badge" style="background:#0f766e;border-color:#0f766e;color:#fff;font-weight:800"><i class="fa-solid fa-calendar-check"></i> Preventive Maintenance</span>
@endif
@if ($ticket->is_emergency)
    <span class="badge" style="background:var(--red);border-color:var(--red);color:#fff;font-weight:800"><i class="fa-solid fa-triangle-exclamation"></i> Emergency</span>
@endif
@if ($ticket->is_confidential)
    <span class="badge st-closed" style="font-weight:800"><i class="fa-solid fa-lock"></i> Confidential</span>
@endif
@if ($paused)
    <span class="badge st-closed" title="The target time stops while we wait for the requester"><i class="fa-solid fa-pause"></i> SLA paused</span>
@endif
@if ($showDue)
    <span class="badge {{ $isOverdue ? 'pr-urgent' : 'st-open' }}" title="Target: {{ $ticket->due_at->format('M j, Y g:i A') }}">
        <i class="fa-regular {{ $isOverdue ? 'fa-bell' : 'fa-clock' }}"></i> {{ $isOverdue ? 'Overdue by '.$dueSpan : 'Due in '.$dueSpan }}
    </span>
@endif
