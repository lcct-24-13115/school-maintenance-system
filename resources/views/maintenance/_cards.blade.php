@use('App\Enums\TicketStatus')

{{-- Phase 5 (design): three columns of .tk-card. The whole card opens the ticket (the location link stretches over it);
     the buttons sit above that link (.tk-foot .btn has z-index 2). Rules for which button shows are unchanged. --}}
@php
    $columns = [
        ['title' => 'NEW', 'icon' => 'fa-inbox', 'tasks' => $newTasks, 'color' => 'var(--deep)'],
        ['title' => 'DOING', 'icon' => 'fa-screwdriver-wrench', 'tasks' => $doingTasks, 'color' => '#b9780f'],
        ['title' => 'DONE (last 7 days)', 'icon' => 'fa-circle-check', 'tasks' => $doneTasks, 'color' => 'var(--green)'],
    ];
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;align-items:start">
    @foreach ($columns as $col)
        <section style="min-width:0">
            <h3 style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;padding:11px 18px;border-radius:16px;background:{{ $col['color'] }};color:#fff;font-family:var(--font-display);font-size:18px;font-weight:600">
                <span><i class="fa-solid {{ $col['icon'] }}"></i> {{ $col['title'] }}</span>
                <span class="nums">{{ $col['tasks']->count() }}</span>
            </h3>

            <div style="display:flex;flex-direction:column;gap:16px">
                @forelse ($col['tasks'] as $r)
                    <article class="tk-card" @if ($r->isEmergency() && ! $r->status->isCompleted()) style="border-color:var(--red);box-shadow:0 0 0 3px rgba(200,55,45,.25)" @endif>
                        <div class="tk-top">
                            <span class="mono" style="font-weight:800">{{ $r->ticket_no }}</span>
                            <span class="tk-sub">{{ $r->submitted_at->diffForHumans() }}</span>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">@include('tickets._badges', ['ticket' => $r])</div>

                        @if ($r->photoUrl())
                            <a href="{{ $r->photoUrl() }}" target="_blank" style="position:relative;z-index:2;display:block;margin-top:6px">
                                <img src="{{ $r->photoUrl() }}" alt="Photo" style="display:block;width:100%;height:170px;object-fit:cover;border-radius:14px">
                            </a>
                        @endif

                        <a href="{{ route('tickets.show', $r) }}" class="tk-title" style="font-size:20px"><i class="fa-solid fa-location-dot" style="color:var(--blue)"></i> {{ $r->location }}</a>
                        <div style="word-break:break-word">{{ $r->description }}</div>
                        <div style="font-weight:700;color:var(--navy);margin-top:4px"><i class="fa-regular fa-user"></i> {{ $r->requester->name }}</div>
                        <div class="tk-sub">{{ $r->department?->name }} · {{ $r->category?->name }}</div>
                        @if ($r->assignee)
                            <div style="font-size:13px;font-weight:700;color:var(--amber)">Assigned to {{ $r->assignee->name }}</div>
                        @endif

                        <div class="tk-foot">
                            <a href="{{ route('tickets.show', $r) }}" class="btn btn-sm" style="width:100%">View details &amp; history</a>

                            @if ($r->status === TicketStatus::Approved || (in_array($r->status, [TicketStatus::Assigned, TicketStatus::Reopened], true) && (int) $r->assigned_to === (int) auth()->id()))
                                <form method="POST" action="{{ route('maintenance.accept', $r) }}" style="width:100%">
                                    @csrf
                                    <button class="btn btn-primary" style="width:100%;height:58px;font-size:18px;letter-spacing:.04em">ACCEPT TASK</button>
                                </form>
                            @elseif ($r->status === TicketStatus::InProgress && $r->isPmGenerated())
                                <a href="{{ route('tickets.show', $r) }}#pm-complete" class="btn btn-primary" style="width:100%;height:58px;font-size:18px;letter-spacing:.04em;background:var(--green);border-color:var(--green);display:flex;align-items:center;justify-content:center">COMPLETE PM CHECKLIST</a>
                            @elseif ($r->status === TicketStatus::InProgress)
                                <form method="POST" action="{{ route('maintenance.complete', $r) }}" style="width:100%">
                                    @csrf
                                    <button class="btn btn-primary" style="width:100%;height:58px;font-size:18px;letter-spacing:.04em;background:var(--green);border-color:var(--green)">MARK COMPLETED</button>
                                </form>
                            @elseif (in_array($r->status, [TicketStatus::Submitted, TicketStatus::PendingAuthorization], true))
                                <div class="hint" style="width:100%;margin:0;text-align:center;font-weight:700"><i class="fa-regular fa-hourglass-half"></i> Waiting for authorization</div>
                            @elseif ($r->status === TicketStatus::Assigned)
                                <div class="hint" style="width:100%;margin:0;text-align:center;font-weight:700">Assigned to {{ $r->assignee?->name }}</div>
                            @elseif ($r->status === TicketStatus::WaitingForUser)
                                <div class="hint" style="width:100%;margin:0;text-align:center;font-weight:700"><i class="fa-regular fa-hourglass-half"></i> Waiting for the requester</div>
                            @elseif ($r->status === TicketStatus::Escalated)
                                <div class="hint" style="width:100%;margin:0;text-align:center;font-weight:700"><i class="fa-solid fa-arrow-up-right-dots"></i> Escalated to the authorizer</div>
                            @elseif ($r->status === TicketStatus::Reopened)
                                <div class="hint" style="width:100%;margin:0;text-align:center;font-weight:700"><i class="fa-solid fa-rotate-left"></i> Reopened</div>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="card" style="margin:0"><x-empty-state icon="fa-regular fa-face-smile" class="compact">Nothing here</x-empty-state></div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
