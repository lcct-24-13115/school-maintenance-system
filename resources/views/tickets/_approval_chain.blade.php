{{--
    Approval chain of a ticket: Vice Principal -> Finance -> President. Purely visual: it only READS $ticket->approvals.
    Usage: @include('tickets._approval_chain', ['ticket' => $ticket])
--}}
@php
    $steps = $ticket->approvals;
    $stopped = $steps->firstWhere('status', \App\Models\TicketApproval::REJECTED);
    $current = $stopped ? null : $steps->firstWhere('status', \App\Models\TicketApproval::PENDING);
@endphp

@if ($steps->isNotEmpty())
    <div class="card" style="margin-top:14px">
        <div class="card-h"><h3>Approval chain</h3></div>
        <div style="display:grid;gap:10px">
            @foreach ($steps as $line)
                @php
                    $isCurrent = $current && $current->id === $line->id;
                    [$icon, $color, $text] = match (true) {
                        $line->status === 'approved' => ['fa-circle-check', 'var(--green)', 'Approved'],
                        $line->status === 'rejected' => ['fa-circle-xmark', 'var(--red)', 'Disapproved'],
                        $isCurrent => ['fa-hourglass-half', '#b7791f', 'Waiting for this person'],
                        $stopped => ['fa-ban', 'var(--text-3)', 'Not reached (stopped earlier)'],
                        default => ['fa-circle', 'var(--text-3)', 'Not yet'],
                    };
                @endphp
                <div style="display:flex;gap:12px;align-items:flex-start;{{ $isCurrent ? 'font-weight:600' : '' }}">
                    <i class="fa-solid {{ $icon }}" style="color:{{ $color }};font-size:20px;margin-top:2px"></i>
                    <div style="min-width:0;word-break:break-word">
                        <div>{{ $line->step_order }}. {{ $line->label }} — <span style="color:{{ $color }}">{{ $text }}</span></div>
                        @if ($line->signed_at)
                            <div class="hint" style="margin:0">{{ $line->signer?->name ?? 'Unknown' }} · {{ $line->signed_at->format('M j, Y g:i A') }}</div>
                        @endif
                        @if ($line->amount !== null)
                            <div class="hint" style="margin:0">Approved amount: ₱{{ number_format((float) $line->amount, 2) }}</div>
                        @endif
                        @if ($line->remarks)
                            <div class="hint" style="margin:0;white-space:pre-line">{{ $line->remarks }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
