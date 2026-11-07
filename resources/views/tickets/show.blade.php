@use('App\Models\RequestStatusLog')

@php
    $fmt = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
    $actionLabels = [
        RequestStatusLog::ACTION_STATUS => 'Status',
        RequestStatusLog::ACTION_ASSIGNMENT => 'Assignment',
        RequestStatusLog::ACTION_REASSIGNMENT => 'Reassignment',
        RequestStatusLog::ACTION_EMERGENCY => 'Emergency flag',
        RequestStatusLog::ACTION_AUTHORIZATION => 'Authorization',
        RequestStatusLog::ACTION_NOTE => 'Note',
        RequestStatusLog::ACTION_ATTACHMENT => 'Attachment',
    ];
    // long free text: keep the line breaks the person typed
    $text = 'white-space:pre-line;word-break:break-word';
    $wide = 'grid-column:1/-1';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $ticket->ticket_no }}</x-slot>

    <div style="max-width:none">

        <p style="margin-bottom:16px">
            <a href="{{ $ticket->status->isTerminal() ? route('tickets.history') : route('tickets.index') }}" class="btn btn-sm"><i class="fa-solid fa-arrow-left"></i> {{ $ticket->status->isTerminal() ? 'Back to ticket history' : 'Back to tickets' }}</a>
        </p>

        @if (session('status'))
            <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
        @endif

        @if ($ticket->is_emergency)
            <div class="alert err">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <b>Emergency</b>
                    <div style="word-break:break-word">{{ $ticket->emergency_reason }}</div>
                    <div style="font-size:12.5px;margin-top:2px">Flagged by {{ $ticket->emergencyFlagger?->name ?? '—' }} · {{ $fmt($ticket->emergency_flagged_at) }}</div>
                </div>
            </div>
        @endif

        {{-- Summary + tracker --}}
        <section class="card">
            <div class="tk-top">
                <span class="mono" style="font-weight:800;font-size:14px">{{ $ticket->ticket_no }}</span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">@include('tickets._badges', ['ticket' => $ticket])</div>

            <h3 style="font-family:var(--font-display);font-size:26px;font-weight:600;color:var(--navy);line-height:1.2;margin-top:12px;word-break:break-word">
                <i class="fa-solid fa-location-dot" style="color:var(--blue);font-size:20px"></i> {{ $ticket->location }}
            </h3>
            <div class="tk-sub" style="font-size:14px">{{ $ticket->category?->name }}@if ($ticket->department) · {{ $ticket->department->name }}@endif</div>

            @include('tickets._tracker', ['ticket' => $ticket, 'full' => true])

            @php $latest = $ticket->statusLogs->sortByDesc('created_at')->first(); @endphp
            @if ($latest)
                <div class="tk-last" style="margin-top:12px">
                    <i class="fa-regular fa-clock"></i>
                    <span><b>Last update</b>{{ $actionLabels[$latest->action] ?? ucfirst(str_replace('_', ' ', $latest->action)) }} · {{ $fmt($latest->created_at) }}</span>
                </div>
            @endif
        </section>

        {{-- People + Dates side by side (stack on phones) --}}
        <div class="pf-grid" style="margin-bottom:20px">
            <section class="card">
                <h3 class="pf-h" style="font-family:var(--font-display);font-size:18px;color:var(--navy);margin-bottom:10px">People</h3>
                <div class="pf-row"><span class="k">Requester</span><span class="mono">{{ $ticket->requester->name }}@if ($ticket->requester->department?->name) · {{ $ticket->requester->department->name }}@endif</span></div>
                <div class="pf-row"><span class="k">Assigned to</span><span class="mono">{{ $ticket->assignee?->name ?? 'Not assigned yet' }}</span></div>
                <div class="pf-row"><span class="k">Emergency flagged by</span><span class="mono">{{ $ticket->emergencyFlagger?->name ?? '—' }}</span></div>
                <div class="pf-row">
                    <span class="k">Authorized by</span>
                    <span class="mono">{{ $ticket->president?->name ?? '—' }}@if ($ticket->president_remarks)<div class="sub" style="color:var(--text-3)">{{ $ticket->president_remarks }}</div>@endif</span>
                </div>
            </section>

            <section class="card">
                <h3 class="pf-h" style="font-family:var(--font-display);font-size:18px;color:var(--navy);margin-bottom:10px">Dates</h3>
                <div class="pf-row"><span class="k">Submitted</span><span class="mono">{{ $fmt($ticket->submitted_at) }}</span></div>
                <div class="pf-row"><span class="k">Authorized</span><span class="mono">{{ $fmt($ticket->president_approved_at) }}</span></div>
                <div class="pf-row"><span class="k">Assigned</span><span class="mono">{{ $fmt($ticket->assigned_at) }}</span></div>
                <div class="pf-row"><span class="k">Work completed</span><span class="mono">{{ $fmt($ticket->completed_at) }}</span></div>
                <div class="pf-row"><span class="k">Closed</span><span class="mono">{{ $fmt($ticket->closed_at) }}</span></div>
            </section>
        </div>

        {{-- Ticket details --}}
        <section class="card">
            <div class="card-h"><h3>Details</h3></div>
            <div class="kv">
                <div><div class="k">Location</div><div style="word-break:break-word">{{ $ticket->location }}</div></div>
                <div><div class="k">Category</div><div>{{ $ticket->category?->name ?? '—' }}</div></div>
                <div><div class="k">Maintenance type</div><div>{{ ucfirst((string) $ticket->maintenance_type) }}</div></div>
                <div><div class="k">Request type</div><div>{{ $ticket->request_type ? ucfirst($ticket->request_type) : '—' }}</div></div>
                <div><div class="k">Department</div><div>{{ $ticket->department?->name ?? '—' }}</div></div>
                <div><div class="k">Date needed</div><div>{{ $ticket->needed_by?->format('M j, Y') ?? '—' }}</div></div>
                <div style="{{ $wide }}"><div class="k">Description</div><div style="{{ $text }}">{{ $ticket->description }}</div></div>
                <div style="{{ $wide }}"><div class="k">Reason</div><div style="{{ $text }}">{{ $ticket->reason ?: '—' }}</div></div>
                @if ($ticket->problems_encountered)
                    <div style="{{ $wide }}"><div class="k">Problems encountered</div><div style="{{ $text }}">{{ $ticket->problems_encountered }}</div></div>
                @endif
                @if ($ticket->recommendations)
                    <div style="{{ $wide }}"><div class="k">Recommendations</div><div style="{{ $text }}">{{ $ticket->recommendations }}</div></div>
                @endif
            </div>
        </section>

        {{-- Items --}}
        @if ($ticket->items->isNotEmpty())
            <section class="card">
                <div class="card-h"><h3>Items</h3></div>
                <div class="table-wrap">
                    <table class="stack">
                        <thead><tr><th>Item</th><th>Quantity</th><th>Unit</th></tr></thead>
                        <tbody>
                            @foreach ($ticket->items as $item)
                                <tr>
                                    <td data-label="Item" style="word-break:break-word">{{ $item->item_name }}@if ($item->specification)<div class="sub">{{ $item->specification }}</div>@endif</td>
                                    <td data-label="Quantity" class="nums">× {{ $item->quantity }}</td>
                                    <td data-label="Unit">{{ $item->unit }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Attachments: opened only through the authorized route (private storage). Files sent inside a reply (Phase 10) show in the conversation instead. --}}
        @php $ticketFiles = $ticket->attachments->whereNull('reply_id'); @endphp
        <section class="card" id="attachments">
            <div class="card-h"><h3>Attachments <span class="hint" style="font-family:var(--font-body);font-weight:400">({{ $ticketFiles->count() }})</span></h3></div>

            @forelse ($ticketFiles as $file)
                <div class="pf-row" style="flex-wrap:wrap">
                    <div style="min-width:0;flex:1">
                        <a href="{{ $file->url() }}" @if ($file->isInline()) target="_blank" rel="noopener" @endif style="word-break:break-all">
                            <span aria-hidden="true">{{ $file->icon() }}</span> {{ $file->original_name }}
                        </a>
                        <div class="sub" style="font-size:12.5px;color:var(--text-3)">
                            {{ $file->sizeForHumans() }} · {{ $file->uploader?->name ?? 'unknown' }} · {{ $file->created_at?->format('M j, Y g:i A') }}
                            @unless ($file->isRequestFile())
                                <span class="badge pr-normal" style="margin-left:4px">{{ $file->kindLabel() }}</span>
                            @endunless
                        </div>
                    </div>
                    <a href="{{ $file->downloadUrl() }}" class="btn btn-sm"><i class="fa-solid fa-download"></i> Download</a>
                </div>
            @empty
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-paperclip"></i>No attachments.</div>
            @endforelse

            @can('addAttachment', $ticket)
                <form method="POST" action="{{ route('tickets.attachments.store', $ticket) }}" enctype="multipart/form-data"
                      style="margin-top:18px;padding-top:18px;border-top:1px solid var(--line)">
                    @csrf
                    <div class="lbl" style="font-size:15px">Add files</div>

                    <x-file-picker name="files" />

                    @if (auth()->user()->can_do('work'))
                        <div class="field" style="margin-top:14px">
                            <label class="lbl" for="attachment-kind">What are these files?</label>
                            <select id="attachment-kind" name="kind">
                                @foreach (\App\Models\RequestAttachment::kindLabels() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('kind')<p class="field-err">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @error('files')<p class="field-err">{{ $message }}</p>@enderror
                    @foreach ($errors->get('files.*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="field-err">{{ $message }}</p>
                        @endforeach
                    @endforeach

                    <button class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-upload"></i> Upload</button>
                </form>
            @endcan
        </section>

        @include('tickets._actions')

        @include('tickets._replies')

        {{-- Full history --}}
        <section class="card">
            <div class="card-h"><h3>History</h3></div>
            <div class="thread" style="margin-bottom:0">
                @forelse ($ticket->statusLogs as $log)
                    <div class="msg">
                        <div class="meta">
                            @if ($log->user)<x-avatar :user="$log->user" :size="28" />@endif
                            <b>{{ $actionLabels[$log->action] ?? ucfirst(str_replace('_', ' ', $log->action)) }}@if ($log->to_status) <span style="font-weight:600;color:var(--text-2)">:
                                @if ($log->from_status){{ \App\Enums\TicketStatus::tryFrom($log->from_status)?->label() }} &rarr; @endif{{ \App\Enums\TicketStatus::tryFrom($log->to_status)?->label() }}</span>@endif</b>
                            <span>· {{ $fmt($log->created_at) }} · {{ $log->user?->name ?? 'System' }}</span>
                        </div>
                        @if ($log->note)
                            <div class="body">{{ $log->note }}</div>
                        @endif
                    </div>
                @empty
                    <div class="empty" style="padding:22px 16px"><i class="fa-regular fa-clock"></i>No history recorded.</div>
                @endforelse
            </div>
        </section>
    </div>

    @push('scripts')
        @include('partials.live-refresh', ['liveTicket' => $ticket])
    @endpush
</x-app-layout>
