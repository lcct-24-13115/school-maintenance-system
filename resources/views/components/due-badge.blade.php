@props(['ticket'])

{{-- "Due in 2 days 4 hours" / "Overdue by 1 day". Only for tickets that are still pending (Merge Phase M1). --}}
@php
    $show = $ticket->due_at !== null && $ticket->status->isPending();
    $overdue = $show && $ticket->due_at->isPast();
    $span = $show ? $ticket->due_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2) : '';
@endphp

@if ($show)
    <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold {{ $overdue ? 'bg-red-100 text-red-800' : 'bg-sky-100 text-sky-800' }}"
          title="Target: {{ $ticket->due_at->format('M j, Y g:i A') }}">
        {{ $overdue ? '⏰ Overdue by '.$span : '🕒 Due in '.$span }}
    </span>
@endif
