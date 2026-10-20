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
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $ticket->ticket_no }}</h2>
            <x-status-badge :status="$ticket->status" />
            <x-ticket-badges :ticket="$ticket" />
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-5xl mx-auto space-y-4 px-3 sm:px-6 lg:px-8">

            <a href="{{ route('tickets.index') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-blue-700 hover:underline">&larr; Back to tickets</a>

            @if (session('status'))
                <div class="rounded-xl bg-green-100 p-4 font-semibold text-green-800">{{ session('status') }}</div>
            @endif

            @if ($ticket->is_emergency)
                <div class="rounded-xl bg-red-50 p-4 text-red-900">
                    <div class="font-extrabold">🚨 Emergency</div>
                    <div class="break-words">{{ $ticket->emergency_reason }}</div>
                    <div class="mt-1 text-sm">Flagged by {{ $ticket->emergencyFlagger?->name ?? '—' }} · {{ $fmt($ticket->emergency_flagged_at) }}</div>
                </div>
            @endif

            {{-- Ticket details --}}
            <section class="rounded-xl bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-lg font-bold text-gray-900">Details</h3>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Location</dt><dd class="break-words">{{ $ticket->location }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Category</dt><dd>{{ $ticket->category?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Maintenance type</dt><dd>{{ ucfirst((string) $ticket->maintenance_type) }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Request type</dt><dd>{{ $ticket->request_type ? ucfirst($ticket->request_type) : '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Department</dt><dd>{{ $ticket->department?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Date needed</dt><dd>{{ $ticket->needed_by?->format('M j, Y') ?? '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-gray-500">Description</dt><dd class="whitespace-pre-line break-words">{{ $ticket->description }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-gray-500">Reason</dt><dd class="whitespace-pre-line break-words">{{ $ticket->reason ?: '—' }}</dd></div>
                    @if ($ticket->problems_encountered)
                        <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-gray-500">Problems encountered</dt><dd class="whitespace-pre-line break-words">{{ $ticket->problems_encountered }}</dd></div>
                    @endif
                    @if ($ticket->recommendations)
                        <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-gray-500">Recommendations</dt><dd class="whitespace-pre-line break-words">{{ $ticket->recommendations }}</dd></div>
                    @endif
                </dl>
            </section>

            {{-- People --}}
            <section class="rounded-xl bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-lg font-bold text-gray-900">People</h3>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Requester</dt><dd>{{ $ticket->requester->name }}<span class="text-gray-500"> · {{ $ticket->requester->department?->name }}</span></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Assigned to</dt><dd>{{ $ticket->assignee?->name ?? 'Not assigned yet' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Emergency flagged by</dt><dd>{{ $ticket->emergencyFlagger?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Authorized by</dt><dd>{{ $ticket->president?->name ?? '—' }}@if ($ticket->president_remarks)<div class="text-sm text-gray-500">{{ $ticket->president_remarks }}</div>@endif</dd></div>
                </dl>
            </section>

            {{-- Timestamps --}}
            <section class="rounded-xl bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-lg font-bold text-gray-900">Dates</h3>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Submitted</dt><dd>{{ $fmt($ticket->submitted_at) }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Authorized</dt><dd>{{ $fmt($ticket->president_approved_at) }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Assigned</dt><dd>{{ $fmt($ticket->assigned_at) }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Work completed</dt><dd>{{ $fmt($ticket->completed_at) }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-gray-500">Closed</dt><dd>{{ $fmt($ticket->closed_at) }}</dd></div>
                </dl>
            </section>

            {{-- Items --}}
            @if ($ticket->items->isNotEmpty())
                <section class="rounded-xl bg-white p-4 shadow-sm">
                    <h3 class="mb-3 text-lg font-bold text-gray-900">Items</h3>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($ticket->items as $item)
                            <li class="py-2 break-words">{{ $item->item_name }} <span class="text-gray-500">× {{ $item->quantity }} {{ $item->unit }}</span>@if ($item->specification)<div class="text-sm text-gray-500">{{ $item->specification }}</div>@endif</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Attachments: opened only through the authorized route (private storage) --}}
            <section class="rounded-xl bg-white p-4 shadow-sm" id="attachments">
                <h3 class="mb-3 text-lg font-bold text-gray-900">Attachments <span class="text-sm font-normal text-gray-500">({{ $ticket->attachments->count() }})</span></h3>

                @forelse ($ticket->attachments as $file)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-3 last:border-0">
                        <div class="min-w-0 flex-1">
                            <a href="{{ $file->url() }}" @if ($file->isInline()) target="_blank" rel="noopener" @endif class="break-all font-semibold text-blue-700 hover:underline">
                                <span aria-hidden="true">{{ $file->icon() }}</span> {{ $file->original_name }}
                            </a>
                            <div class="text-xs text-gray-500">
                                {{ $file->sizeForHumans() }} · {{ $file->uploader?->name ?? 'unknown' }} · {{ $file->created_at?->format('M j, Y g:i A') }}
                                @unless ($file->isRequestFile())
                                    <span class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 font-semibold text-indigo-800">{{ $file->kindLabel() }}</span>
                                @endunless
                            </div>
                        </div>
                        <a href="{{ $file->downloadUrl() }}" class="inline-flex min-h-[44px] items-center rounded-lg border border-gray-300 px-4 text-sm font-bold text-gray-700 hover:bg-gray-50">Download</a>
                    </div>
                @empty
                    <p class="text-gray-500">No attachments.</p>
                @endforelse

                @can('addAttachment', $ticket)
                    <form method="POST" action="{{ route('tickets.attachments.store', $ticket) }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-gray-100 pt-4">
                        @csrf
                        <div class="text-base font-semibold text-gray-800">Add files</div>

                        <x-file-picker name="files" />

                        @if (auth()->user()->can_do('work'))
                            <div>
                                <label class="block text-sm font-semibold text-gray-700">What are these files?</label>
                                <select name="kind" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base sm:w-auto">
                                    @foreach (\App\Models\RequestAttachment::kindLabels() as $value => $label)
                                        <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('kind')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        @error('files')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @foreach ($errors->get('files.*') as $messages)
                            @foreach ((array) $messages as $message)
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @endforeach
                        @endforeach

                        <button class="min-h-[48px] w-full rounded-xl bg-blue-600 px-6 py-3 text-base font-bold text-white hover:bg-blue-700 sm:w-auto">Upload</button>
                    </form>
                @endcan
            </section>

            {{-- Actions: empty for now. Phase 4 puts authorize / assign / flag / close here. --}}
            <section class="rounded-xl border-2 border-dashed border-gray-200 p-4" id="actions">
                <h3 class="mb-1 text-lg font-bold text-gray-900">Actions</h3>
                <p class="text-sm text-gray-500">No actions are available on this page yet.</p>
            </section>

            {{-- Full history --}}
            <section class="rounded-xl bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-lg font-bold text-gray-900">History</h3>
                <ol class="space-y-4 border-s-2 border-gray-200 ps-4">
                    @forelse ($ticket->statusLogs as $log)
                        <li class="relative">
                            <span class="absolute -start-[1.4rem] top-1.5 h-3 w-3 rounded-full bg-blue-600"></span>
                            <div class="text-xs text-gray-500">{{ $fmt($log->created_at) }} · {{ $log->user?->name ?? 'System' }}</div>
                            <div class="font-semibold text-gray-900">
                                {{ $actionLabels[$log->action] ?? ucfirst(str_replace('_', ' ', $log->action)) }}
                                @if ($log->to_status)
                                    <span class="font-normal text-gray-600">:
                                        @if ($log->from_status){{ \App\Enums\TicketStatus::tryFrom($log->from_status)?->label() }} &rarr; @endif{{ \App\Enums\TicketStatus::tryFrom($log->to_status)?->label() }}</span>
                                @endif
                            </div>
                            @if ($log->note)
                                <div class="break-words text-sm text-gray-700">{{ $log->note }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-gray-500">No history recorded.</li>
                    @endforelse
                </ol>
            </section>
        </div>
    </div>
</x-app-layout>
