{{--
    Phase 4 (design): delivery-style tracker (.trk) for a ticket. Purely visual: it only READS $ticket->status
    (and a few timestamps); it changes nothing.
    4 stages: Filed -> Authorized -> In work -> Done.   Disapproved stops at step 1 in red.
    Phase 7: Waiting for User / Escalated stay on the stage the ticket had reached (read from its timestamps),
    Reopened goes back to "In work", Cancelled stops at step 1 in grey.
    Usage: @include('tickets._tracker', ['ticket' => $ticket, 'full' => true])
--}}
@php
    $steps = [
        ['Filed', 'fa-file-pen', $ticket->submitted_at],
        ['Authorized', 'fa-stamp', $ticket->president_approved_at],
        ['In work', 'fa-screwdriver-wrench', $ticket->assigned_at],
        ['Done', 'fa-circle-check', $ticket->completed_at ?? $ticket->closed_at],
    ];

    // Where a ticket that is waiting / escalated was: assigned -> In work, authorized -> Authorized, else Filed.
    $reached = $ticket->assigned_at ? 2 : ($ticket->president_approved_at ? 1 : 0);

    // [current step (4 = everything done), colour flag, headline, detail line]
    [$now, $flag, $head, $sub] = match ($ticket->status->value) {
        'submitted' => [0, '', 'Filed', 'Waiting for review'],
        'pending_authorization' => [0, 'st-warn', 'Waiting for authorization', 'With the authorizer'],
        'approved' => [1, '', 'Authorized', 'Waiting to be assigned'],
        'assigned' => [2, '', 'Assigned', $ticket->assignee ? 'To '.$ticket->assignee->name : 'Work has not started yet'],
        'in_progress' => [2, '', 'Work in progress', $ticket->assignee ? 'By '.$ticket->assignee->name : ''],
        'work_completed' => [3, 'st-warn', 'Work completed', 'Waiting to be closed'],
        'closed' => [4, '', 'Closed', 'This ticket is finished'],
        'disapproved' => [0, 'st-alert', 'Disapproved', 'This ticket will not be worked on'],
        'waiting_for_user' => [$reached, 'st-warn', 'Waiting for the requester', 'More information is needed from the requester'],
        'escalated' => [$reached, 'st-alert', 'Escalated', 'Waiting for the President / authorizer'],
        'cancelled' => [0, 'st-off', 'Cancelled', 'This ticket was cancelled and will not be worked on'],
        'reopened' => [2, 'st-warn', 'Reopened', 'Sent back for more work'],
        default => [0, '', $ticket->status->label(), ''],
    };

    $isFull = $full ?? false;
@endphp
<div class="trk {{ $isFull ? 'full' : '' }}" role="group" aria-label="Ticket progress: {{ $head }}">
    <div class="trk-head">
        <b>{{ $head }}</b>
        @if ($sub !== '')<span>{{ $sub }}</span>@endif
    </div>
    <ol class="trk-steps">
        @foreach ($steps as $i => $step)
            @php $state = $i < $now ? 'done' : ($i === $now ? 'now' : ''); @endphp
            <li class="{{ $state }} {{ $state === 'now' ? $flag : '' }}" @if ($state === 'now') aria-current="step" @endif>
                <span class="dot"><i class="fa-solid {{ $state === 'done' ? 'fa-check' : $step[1] }}"></i></span>
                <span class="lb">{{ $step[0] }}</span>
                @if ($isFull && $step[2] && $state !== '')
                    <span class="sb">{{ $step[2]->format('M j') }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</div>
