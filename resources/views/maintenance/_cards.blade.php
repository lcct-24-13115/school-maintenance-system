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
                    <article class="overflow-hidden rounded-xl bg-white shadow {{ $r->isEmergency() && $r->work_status !== 'completed' ? 'ring-4 ring-red-500' : '' }}">
                        @if ($r->isEmergency())
                            <div class="bg-red-600 px-4 py-1 text-center text-sm font-extrabold uppercase tracking-widest text-white">🚨 Emergency</div>
                        @endif

                        @if ($r->photoUrl())
                            <a href="{{ $r->photoUrl() }}" target="_blank"><img src="{{ $r->photoUrl() }}" alt="Photo" class="h-44 w-full object-cover"></a>
                        @endif

                        <div class="space-y-1 p-4">
                            <div class="text-2xl font-extrabold text-gray-900">📍 {{ $r->location }}</div>
                            <div class="text-base text-gray-800">{{ $r->job_description }}</div>
                            <div class="pt-1 text-base font-semibold text-gray-700">👤 {{ $r->requester->name }}</div>
                            <div class="text-sm text-gray-500">{{ $r->department?->name }} · {{ $r->reference_no }} · {{ $r->created_at->diffForHumans() }}</div>
                            @if ($r->assignee)
                                <div class="text-sm font-semibold text-amber-700">Assigned to {{ $r->assignee->name }}</div>
                            @endif
                        </div>

                        @if ($r->work_status === 'pending')
                            <form method="POST" action="{{ route('maintenance.accept', $r) }}">
                                @csrf
                                <button class="w-full bg-blue-600 py-5 text-2xl font-extrabold text-white active:bg-blue-800">ACCEPT TASK</button>
                            </form>
                        @elseif ($r->work_status === 'accepted')
                            <form method="POST" action="{{ route('maintenance.complete', $r) }}">
                                @csrf
                                <button class="w-full bg-green-600 py-5 text-2xl font-extrabold text-white active:bg-green-800">MARK COMPLETED</button>
                            </form>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg bg-white p-6 text-center text-gray-400">Nothing here 👍</p>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
