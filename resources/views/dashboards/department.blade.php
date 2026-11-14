@php
    $isSystemAdmin = $isSystemAdmin ?? false;
    $user = auth()->user();
    $departmentId = $department?->id ?? $user->scopedDepartmentId();
    $links = $isSystemAdmin ? [] : [
        'Assigned to me' => route('tickets.index', ['assigned_to' => $user->id]),
        'My assigned tickets' => route('tickets.index', ['assigned_to' => $user->id]),
        'Unassigned department queue' => route('tickets.index', ['department_id' => $departmentId, 'assigned_to' => 'unassigned']),
        'Unassigned queue' => route('tickets.index', ['department_id' => $departmentId, 'assigned_to' => 'unassigned']),
        'Overdue' => route('tickets.index', ['department_id' => $departmentId, 'overdue' => 1]),
        'All department tickets' => route('tickets.index', ['department_id' => $departmentId]),
        'Open department tickets' => route('tickets.index', ['department_id' => $departmentId]),
    ];
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ @filemtime(public_path('css/dashboard.css')) }}">

    <x-slot name="header">{{ $title }}</x-slot>

    <section class="hero" style="--hero:url('{{ asset('images/campus.jpg') }}')">
        <div class="hero-in">
            <div class="hero-txt">
                <span class="hero-eye">{{ $title }}</span>
                <h2>Hi {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}, welcome back</h2>
                @if ($isSystemAdmin)
                    <p>Ticket totals are grouped by the department responsible for receiving each ticket.</p>
                    <div class="hero-act"><a href="{{ route('tickets.index') }}" class="btn btn-primary"><i class="fa-solid fa-list-check"></i> View tickets</a></div>
                @else
                    <p>{{ $department?->name ?? 'No department assigned' }} · signed in as <b>{{ $user->role->name }}</b>.</p>
                    <div class="hero-act">
                        <a href="{{ route('tickets.index', ['department_id' => $departmentId]) }}" class="btn btn-primary"><i class="fa-solid fa-list-check"></i> Department tickets</a>
                        @if ($isHead)<a href="{{ route('maintenance.board') }}" class="btn"><i class="fa-solid fa-table-columns"></i> Task board</a>@endif
                    </div>
                @endif
            </div>
            <div class="hero-last">
                <span class="hl-k"><i class="fa-regular fa-clock"></i> Last update</span>
                <b>{{ $lastUpdate ? $lastUpdate->format('M j, Y g:i A') : '—' }}</b>
                <small>{{ $lastUpdate ? $lastUpdate->diffForHumans() : 'No tickets yet' }}</small>
            </div>
        </div>
    </section>

    <h2 class="dash-sec"><i class="fa-solid fa-bolt"></i> {{ $isSystemAdmin ? 'System overview' : 'Department queue' }}</h2>
    <div class="stats">
        @foreach ($cards as $label => $count)
            @php
                $look = str_contains(strtolower($label), 'overdue') || str_contains(strtolower($label), 'escalated')
                    ? ['fa-triangle-exclamation', 'var(--red)']
                    : (str_contains(strtolower($label), 'unassigned') ? ['fa-inbox', 'var(--gold)'] : ['fa-ticket', 'var(--blue)']);
                $url = $links[$label] ?? null;
            @endphp
            @if ($url)
                <a href="{{ $url }}" class="stat {{ $count === 0 ? 'is-zero' : '' }}" style="--c:{{ $look[1] }};text-decoration:none">
            @else
                <div class="stat {{ $count === 0 ? 'is-zero' : '' }}" style="--c:{{ $look[1] }}">
            @endif
                <span class="ic"><i class="fa-solid {{ $look[0] }}"></i></span>
                <div class="k">{{ $label }}</div>
                <div class="v nums">{{ $count }}</div>
                @if ($url)<div class="s">View list <i class="fa-solid fa-arrow-right"></i></div>@endif
            @if ($url)</a>@else</div>@endif
        @endforeach
    </div>

    @if ($isSystemAdmin)
        <div class="card">
            <div class="card-h"><h3>Tickets by department</h3><small>Receiving department</small></div>
            <div class="table-wrap">
                <table class="stack">
                    <thead><tr><th>Department</th><th>Status</th><th>Total tickets</th><th>Open</th><th>Overdue</th></tr></thead>
                    <tbody>
                        @foreach ($departments as $row)
                            <tr>
                                <td data-label="Department"><b>{{ $row['name'] }}</b>@if ($row['code']) <span class="sub">({{ $row['code'] }})</span>@endif</td>
                                <td data-label="Status">{{ $row['active'] ? 'Active' : 'Inactive' }}</td>
                                <td data-label="Total tickets"><a href="{{ route('tickets.index', ['department_id' => $row['id']]) }}">{{ $row['total'] }}</a></td>
                                <td data-label="Open"><a href="{{ route('tickets.index', ['department_id' => $row['id']]) }}">{{ $row['open'] }}</a></td>
                                <td data-label="Overdue"><a href="{{ route('tickets.index', ['department_id' => $row['id'], 'overdue' => 1]) }}">{{ $row['overdue'] }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @if ($isHead)
            <div class="card">
                <div class="card-h"><h3>Staff workload</h3><small>Open tickets assigned per person in this department</small></div>
                @if (count($workload) === 0)
                    <div class="empty"><i class="fa-solid fa-users"></i>No open tickets are assigned to staff.</div>
                @else
                    <div class="table-wrap">
                        <table class="stack">
                            <thead><tr><th>Staff member</th><th>Open tickets</th></tr></thead>
                            <tbody>
                                @foreach ($workload as $row)
                                    <tr><td data-label="Staff member">{{ $row['name'] }}</td><td data-label="Open tickets"><a href="{{ route('tickets.index', ['department_id' => $departmentId, 'assigned_to' => $row['id']]) }}">{{ $row['open'] }}</a></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-h"><h3>Escalated tickets</h3><a class="more" href="{{ route('tickets.index', ['department_id' => $departmentId, 'status' => [\App\Enums\TicketStatus::Escalated->value]]) }}">View all <i class="fa-solid fa-arrow-right"></i></a></div>
                @if ($escalatedTickets->isEmpty())
                    <div class="empty"><i class="fa-solid fa-circle-check"></i>No escalated tickets.</div>
                @else
                    <div class="table-wrap"><table class="stack">
                        <thead><tr><th>Ticket</th><th>Assigned to</th><th>Updated</th></tr></thead>
                        <tbody>@foreach ($escalatedTickets as $ticket)
                            <tr><td data-label="Ticket"><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->ticket_no }}</a></td><td data-label="Assigned to">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td><td data-label="Updated">{{ $ticket->updated_at?->diffForHumans() }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </div>
        @endif

        <div class="card">
            <div class="card-h"><h3>{{ $isHead ? 'Department open tickets' : 'Assigned to me' }}</h3><a class="more" href="{{ $isHead ? route('tickets.index', ['department_id' => $departmentId]) : route('tickets.index', ['assigned_to' => $user->id]) }}">View all <i class="fa-solid fa-arrow-right"></i></a></div>
            @if ($tickets->isEmpty())
                <div class="empty"><i class="fa-solid fa-circle-check"></i>No open tickets in this queue.</div>
            @else
                <div class="table-wrap"><table class="stack">
                    <thead><tr><th>Ticket</th><th>Category</th><th>Status</th><th>Assigned to</th><th>Target date</th></tr></thead>
                    <tbody>@foreach ($tickets as $ticket)
                        <tr>
                            <td data-label="Ticket"><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->ticket_no }}</a><div class="sub">{{ \Illuminate\Support\Str::limit($ticket->description, 55) }}</div></td>
                            <td data-label="Category">{{ $ticket->category?->name ?? '—' }}</td>
                            <td data-label="Status"><x-status-badge :status="$ticket->status" /></td>
                            <td data-label="Assigned to">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                            <td data-label="Target date">{{ $ticket->due_at?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </div>
    @endif

    @push('scripts')
        @include('partials.live-refresh')
    @endpush
</x-app-layout>
