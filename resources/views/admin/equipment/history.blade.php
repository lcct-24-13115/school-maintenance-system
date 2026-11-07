<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">Maintenance history</h2>
    </x-slot>

    <div class="content p6-wrap">

        <div style="margin-bottom:14px">
            <a href="{{ route('admin.equipment.index') }}" class="btn"><i class="fa-solid fa-arrow-left"></i> Equipment</a>
        </div>

        {{-- who / what --}}
        <div class="card">
            <h3 class="p6-card-t" style="margin-bottom:4px;word-break:break-word">{{ $equipment->name }}</h3>
            <div style="font-size:13.5px;color:var(--text-2)">
                {{ $equipment->facility?->name }}
                @if ($equipment->category) &middot; {{ $equipment->category }} @endif
                @if ($equipment->serial_no) &middot; Serial no. {{ $equipment->serial_no }} @endif
            </div>
            @if ($equipment->notes)
                <p style="margin:10px 0 0;font-size:13.5px;white-space:pre-line;word-break:break-word">{{ $equipment->notes }}</p>
            @endif
        </div>

        {{-- numbers --}}
        <div class="stats">
            <div class="stat" style="--c:var(--blue)">
                <div class="k">PM tickets</div>
                <div class="v">{{ $stats['total'] }}</div>
                <div class="s">all time</div>
            </div>
            <div class="stat" style="--c:var(--green, #15803d)">
                <div class="k">Done</div>
                <div class="v">{{ $stats['done'] }}</div>
                <div class="s">{{ $stats['last_done'] ? 'last: '.$stats['last_done']->format('M j, Y') : 'nothing done yet' }}</div>
            </div>
            <div class="stat" style="--c:var(--amber, #b45309)">
                <div class="k">Open</div>
                <div class="v">{{ $stats['open'] }}</div>
                <div class="s">still to do</div>
            </div>
            <div class="stat" style="--c:var(--red)">
                <div class="k">Overdue</div>
                <div class="v {{ $stats['overdue'] > 0 ? 'red' : '' }}">{{ $stats['overdue'] }}</div>
                <div class="s">past the due date</div>
            </div>
        </div>

        {{-- schedules of this equipment --}}
        <div class="card" style="padding:0">
            <div style="padding:14px 16px 0"><h3 class="p6-card-t" style="margin:0">PM schedules</h3></div>
            @if ($schedules->isEmpty())
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-calendar-check"></i>This equipment has no PM schedule yet.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Schedule</th>
                                <th>Repeats</th>
                                <th>Next due</th>
                                <th>Last done</th>
                                <th>Assigned to</th>
                                <th style="width:110px">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedules as $s)
                                <tr>
                                    <td data-label="Schedule" style="word-break:break-word"><b>{{ $s->title }}</b></td>
                                    <td data-label="Repeats">{{ $s->frequencyText() }}</td>
                                    <td data-label="Next due">{{ $s->next_due_date?->format('M j, Y') ?? '—' }}</td>
                                    <td data-label="Last done">{{ $s->last_completed_date?->format('M j, Y') ?? '—' }}</td>
                                    <td data-label="Assigned to">{{ $s->assignee?->name ?? '—' }}</td>
                                    <td data-label="Status">
                                        <span class="badge {{ $s->is_active ? 'st-open' : 'st-closed' }}">{{ $s->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- filters --}}
        <form method="GET" action="{{ route('admin.equipment.history', $equipment) }}" class="card">
            <div class="filters" style="margin-bottom:0">
                <select name="status" aria-label="Show">
                    <option value="">All tickets</option>
                    <option value="open" @selected($filters['status'] === 'open')>Open</option>
                    <option value="done" @selected($filters['status'] === 'done')>Done</option>
                    <option value="overdue" @selected($filters['status'] === 'overdue')>Overdue</option>
                </select>

                <select name="schedule" aria-label="Schedule">
                    <option value="">All schedules</option>
                    @foreach ($schedules as $s)
                        <option value="{{ $s->id }}" @selected($filters['schedule'] === (string) $s->id)>{{ $s->title }}</option>
                    @endforeach
                </select>

                <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                @if ($filters['status'] !== '' || $filters['schedule'] !== '')
                    <a href="{{ route('admin.equipment.history', $equipment) }}" class="btn">Clear</a>
                @endif
            </div>
        </form>

        {{-- the history --}}
        <div class="card" style="padding:0">
            @if ($tickets->isEmpty())
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-clock-rotate-left"></i>
                    {{ $stats['total'] === 0 ? 'No maintenance has been recorded for this equipment yet.' : 'No tickets match this filter.' }}
                </div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Schedule</th>
                                <th>Due</th>
                                <th>Done on</th>
                                <th>Technician</th>
                                <th>Checklist</th>
                                <th>Notes</th>
                                <th style="width:150px">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tickets as $t)
                                @php
                                    $late = $t->pm_completed_date === null && $t->status->isPending() && $t->pm_due_date !== null
                                        && $t->pm_due_date->toDateString() < $today;
                                @endphp
                                <tr>
                                    <td data-label="Ticket" style="white-space:nowrap">
                                        <a href="{{ route('tickets.show', $t) }}"><b>{{ $t->ticket_no }}</b></a>
                                    </td>
                                    <td data-label="Schedule" style="word-break:break-word">{{ $t->pmSchedule?->title ?? '—' }}</td>
                                    <td data-label="Due" style="white-space:nowrap">
                                        {{ $t->pm_due_date?->format('M j, Y') ?? '—' }}
                                        @if ($late)<div class="sub" style="font-size:12.5px;color:var(--red);font-weight:700">Overdue</div>@endif
                                    </td>
                                    <td data-label="Done on" style="white-space:nowrap">{{ $t->pm_completed_date?->format('M j, Y') ?? '—' }}</td>
                                    <td data-label="Technician">{{ $t->pmTechnician?->name ?? ($t->pm_completed_date ? '—' : ($t->assignee?->name ?? '—')) }}</td>
                                    <td data-label="Checklist">{{ $t->checklist_total > 0 ? $t->checklist_done.' / '.$t->checklist_total : '—' }}</td>
                                    <td data-label="Notes" style="word-break:break-word;max-width:260px">{{ $t->pm_notes ? \Illuminate\Support\Str::limit($t->pm_notes, 90) : '—' }}</td>
                                    <td data-label="Status"><span class="badge {{ $t->status->badgeClass() }}">{{ $t->status->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @include('partials.pager', ['paginator' => $tickets])
    </div>
</x-app-layout>