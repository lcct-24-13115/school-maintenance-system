{{-- Head of Maintenance: tell the requester the estimated completion date (after the President approved) --}}
@php
    $estCan = auth()->check()
        && \App\Services\TicketEstimate::canSet(auth()->user(), $ticket)
        && in_array($ticket->status, \App\Services\TicketWorkflow::assignableStatuses(), true);
    $estHas = \App\Services\TicketEstimate::has($ticket);
    $estDate = \App\Services\TicketEstimate::date($ticket);
@endphp
@if ($estCan)
    <section class="card" id="estimate">
        <div class="card-h"><h3><i class="fa-solid fa-calendar-check"></i> Estimated completion date</h3></div>

        @if (session('estimate_error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('estimate_error') }}</div></div>
        @endif

        <form method="POST" action="{{ route('tickets.estimate', $ticket) }}">
            @csrf
            <p class="hint" style="margin:0 0 10px">
                @if ($estHas)
                    Now: <strong>{{ $estDate->format('M j, Y') }}</strong>. A new date is sent to the requester as a revised estimate.
                @else
                    Tell <strong>{{ $ticket->requester->name }}</strong> when this work should be finished.
                @endif
            </p>
            <div class="field">
                <label for="estimated_date" class="lbl">Estimated completion date (required)</label>
                <input id="estimated_date" type="date" name="estimated_date" min="{{ now()->toDateString() }}" value="{{ old('estimated_date', $estHas ? $estDate->toDateString() : $ticket->needed_by?->toDateString()) }}" required>
                @if ($errors->estimate->has('estimated_date'))<p class="field-err">{{ $errors->estimate->first('estimated_date') }}</p>@endif
            </div>
            <div class="field">
                <label for="estimate_message" class="lbl">Message to the requester (optional)</label>
                <textarea id="estimate_message" name="estimate_message" rows="2" maxlength="500" placeholder="e.g. We are waiting for the materials to arrive." style="min-height:84px">{{ old('estimate_message') }}</textarea>
                @if ($errors->estimate->has('estimate_message'))<p class="field-err">{{ $errors->estimate->first('estimate_message') }}</p>@endif
            </div>
            <button class="btn btn-primary">{{ $estHas ? 'Send revised date' : 'Send estimated date' }}</button>
        </form>
    </section>
@endif
