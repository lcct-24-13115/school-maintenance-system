@props(['ticket'])

{{-- "Due in 2 days 4 hours" / "Overdue by 1 day". Only for tickets that are still pending (Merge Phase M1). --}}
@php
    $paused = $ticket->status === \App\Enums\TicketStatus::WaitingForUser;   // Phase 9: SLA clock paused
    $show = $ticket->due_at !== null && $ticket->status->isPending() && ! $paused;
    $overdue = $show && $ticket->due_at->isPast();
    $span = $show ? $ticket->due_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2) : '';
@endphp

@if ($paused)
    <span class="badge st-closed" title="The target time stops while we wait for the requester"><i class="fa-solid fa-pause"></i> SLA paused</span>
@elseif ($show)
    <span class="badge {{ $overdue ? 'st-escalated' : 'st-open' }}"
          title="Target: {{ $ticket->due_at->format('M j, Y g:i A') }}">
        <i class="fa-solid {{ $overdue ? 'fa-clock' : 'fa-hourglass-half' }}"></i>
        {{ $overdue ? 'Overdue by '.$span : 'Due in '.$span }}
    </span>
@endif
