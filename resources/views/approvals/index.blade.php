@php
    $fmt = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
    $area = 'min-height:84px';
@endphp

<x-app-layout>
    <x-slot name="header">Approvals</x-slot>

    <div style="max-width:none">

        @if (session('status'))
            <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('error') }}</div></div>
        @endif

        @foreach (['approve', 'disapprove'] as $bag)
            @foreach ($errors->getBag($bag)->all() as $message)
                <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>
            @endforeach
        @endforeach

        <p class="page-sub" style="margin:0 0 18px">
            Tickets waiting for your authorization. Emergencies are first, then the oldest request.
            The app records who authorized and when; it does not replace the official signatures.
            To attach a signed file, open the ticket.
        </p>

        @forelse ($tickets as $ticket)
            <article class="card" id="ticket-{{ $ticket->id }}" @if ($ticket->is_emergency) style="border-color:var(--red);box-shadow:0 0 0 3px rgba(200,55,45,.18)" @endif>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                    <a href="{{ route('tickets.show', $ticket) }}" style="font-weight:800">{{ $ticket->ticket_no }}</a>
                    @include('tickets._badges', ['ticket' => $ticket])
                </div>

                <h3 style="font-family:var(--font-display);font-size:21px;font-weight:600;color:var(--navy);margin-top:8px;word-break:break-word">
                    <i class="fa-solid fa-location-dot" style="color:var(--blue);font-size:17px"></i> {{ $ticket->headline() }}
                </h3>
                <div style="white-space:pre-line;word-break:break-word;margin-top:4px">{{ $ticket->description }}</div>
                @if ($ticket->is_emergency)
                    <div class="alert err" style="margin:10px 0 0"><i class="fa-solid fa-triangle-exclamation"></i><div style="word-break:break-word"><b>Emergency:</b> {{ $ticket->emergency_reason }}</div></div>
                @endif
                <div class="tk-sub" style="margin-top:8px;font-size:13px">
                    {{ $ticket->requester->name }} · {{ $ticket->department?->name }} · {{ $ticket->category?->name }} · submitted {{ $fmt($ticket->submitted_at) }}
                </div>

                <div class="row2" style="margin-top:16px;padding-top:16px;border-top:1px dashed var(--line);align-items:start">
                    <form method="POST" action="{{ route('tickets.approve', $ticket) }}" style="background:var(--green-dim);border:1.5px solid #bfe6cc;border-radius:18px;padding:14px 16px">
                        @csrf
                        <input type="hidden" name="return" value="approvals">
                        <div class="field">
                            <label for="remarks-{{ $ticket->id }}" class="lbl">Remarks (optional)</label>
                            <textarea id="remarks-{{ $ticket->id }}" name="remarks" rows="2" style="{{ $area }}"></textarea>
                        </div>
                        <button class="btn btn-primary" style="background:var(--green);border-color:var(--green);width:100%"><i class="fa-solid fa-circle-check"></i> Approve</button>
                    </form>

                    <form method="POST" action="{{ route('tickets.disapprove', $ticket) }}" style="background:var(--red-dim);border:1.5px solid #f3c9c5;border-radius:18px;padding:14px 16px">
                        @csrf
                        <input type="hidden" name="return" value="approvals">
                        <div class="field">
                            <label for="reason-{{ $ticket->id }}" class="lbl">Reason to disapprove (required)</label>
                            <textarea id="reason-{{ $ticket->id }}" name="reason" rows="2" required style="{{ $area }}"></textarea>
                        </div>
                        <button class="btn btn-danger" style="width:100%"><i class="fa-solid fa-circle-xmark"></i> Disapprove</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="card">
                <x-empty-state icon="fa-regular fa-circle-check" title="All clear.">Nothing is waiting for your authorization.</x-empty-state>
            </div>
        @endforelse

        @include('partials.pager', ['paginator' => $tickets])
    </div>
</x-app-layout>
