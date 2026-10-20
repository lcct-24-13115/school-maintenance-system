@use('App\Enums\TicketStatus')

@php
    $columns = [
        ['title' => '🆕 NEW', 'tasks' => $newTasks, 'color' => 'bg-blue-600'],
        ['title' => '🔧 DOING', 'tasks' => $doingTasks, 'color' => 'bg-amber-500'],
        ['title' => '✅ DONE (last 7 days)', 'tasks' => $doneTasks, 'color' => 'bg-green-600'],
    ];
@endphp

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    @foreach ($columns as $col)
        <section>
            <h3 class="mb-3 rounded-lg {{ $col['color'] }} px-4 py-2 text-lg font-extrabold text-white">
                {{ $col['title'] }} <span class="float-right">{{ $col['tasks']->count() }}</span>
            </h3>

            <div class="space-y-4">
                @forelse ($col['tasks'] as $r)
                    <article class="overflow-hidden rounded-xl bg-white shadow {{ $r->isEmergency() && ! $r->status->isCompleted() ? 'ring-4 ring-red-500' : '' }}">
                        @if ($r->isEmergency())
                            <div class="bg-red-600 px-4 py-1 text-center text-sm font-extrabold uppercase tracking-widest text-white">🚨 Emergency</div>
                        @endif
                        @if ($r->is_confidential)
                            <div class="bg-gray-800 px-4 py-1 text-center text-xs font-bold uppercase tracking-widest text-white">🔒 Confidential</div>
                        @endif

                        @if ($r->photoUrl())
                            <a href="{{ $r->photoUrl() }}" target="_blank"><img src="{{ $r->photoUrl() }}" alt="Photo" class="h-44 w-full object-cover"></a>
                        @endif

                        <div class="space-y-1 p-4">
                            <div class="text-2xl font-extrabold text-gray-900">📍 {{ $r->location }}</div>
                            <div class="text-base text-gray-800">{{ $r->description }}</div>
                            <div class="pt-1 text-base font-semibold text-gray-700">👤 {{ $r->requester->name }}</div>
                            <div class="text-sm text-gray-500">{{ $r->department?->name }} · {{ $r->category?->name }}</div>
                            <div class="text-sm text-gray-500">{{ $r->ticket_no }} · {{ $r->submitted_at->diffForHumans() }}</div>
                            <div class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-700">{{ $r->status->label() }}</div>
                            @if ($r->assignee)
                                <div class="text-sm font-semibold text-amber-700">Assigned to {{ $r->assignee->name }}</div>
                            @endif
                        </div>

                        <a href="{{ route('tickets.show', $r) }}" class="flex min-h-[44px] items-center justify-center border-t border-gray-100 text-sm font-bold text-blue-700 hover:bg-blue-50">View details &amp; history</a>

                        @if ($r->status === TicketStatus::Approved || ($r->status === TicketStatus::Assigned && (int) $r->assigned_to === (int) auth()->id()))
                            <form method="POST" action="{{ route('maintenance.accept', $r) }}">
                                @csrf
                                <button class="w-full bg-blue-600 py-5 text-2xl font-extrabold text-white active:bg-blue-800">ACCEPT TASK</button>
                            </form>
                        @elseif ($r->status === TicketStatus::InProgress)
                            <form method="POST" action="{{ route('maintenance.complete', $r) }}">
                                @csrf
                                <button class="w-full bg-green-600 py-5 text-2xl font-extrabold text-white active:bg-green-800">MARK COMPLETED</button>
                            </form>
                        @elseif (in_array($r->status, [TicketStatus::Submitted, TicketStatus::PendingAuthorization], true))
                            <div class="bg-gray-100 py-4 text-center text-base font-bold text-gray-500">⏳ Waiting for authorization</div>
                        @elseif ($r->status === TicketStatus::Assigned)
                            <div class="bg-gray-100 py-4 text-center text-base font-bold text-gray-500">Assigned to {{ $r->assignee?->name }}</div>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg bg-white p-6 text-center text-gray-400">Nothing here 👍</p>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
