{{-- What the Head of Maintenance told the requester: the estimated completion date --}}
@php
    $etaHas = \App\Services\TicketEstimate::has($ticket);
    $etaDate = \App\Services\TicketEstimate::date($ticket);
@endphp
@if ($ticket->president_approved_at && $ticket->status->isPending())
    @if ($etaHas)
        <div class="alert ok" style="margin-top:14px;margin-bottom:0">
            <i class="fa-solid fa-calendar-check"></i>
            <div>
                <b>Estimated completion: {{ $etaDate->format('F j, Y') }}</b>
                @if ($ticket->eta_message)
                    <div style="word-break:break-word;white-space:pre-line">{{ $ticket->eta_message }}</div>
                @endif
                <div style="font-size:12.5px;margin-top:2px">
                    Set by {{ \App\Models\User::find($ticket->eta_set_by)?->name ?? 'the Head of Maintenance' }}
                    · {{ \Illuminate\Support\Carbon::parse($ticket->eta_set_at)->format('M j, Y g:i A') }}
                </div>
            </div>
        </div>
    @elseif ((int) $ticket->requester_id === (int) auth()->id())
        <div class="alert" style="margin-top:14px;margin-bottom:0">
            <i class="fa-regular fa-calendar"></i>
            <div>Your request is approved. The Head of Maintenance will give you an estimated completion date soon.</div>
        </div>
    @endif
@endif
