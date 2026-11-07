@php
    // 0.5 -> "30 min", 5 -> "5.0 h", 72 -> "3.0 days"
    $hours = fn ($h) => $h === null ? '—' : ($h < 1 ? round($h * 60).' min' : ($h >= 48 ? number_format($h / 24, 1).' days' : number_format($h, 1).' h'));
    $range = match (true) {
        $filters['from'] && $filters['to'] => $filters['from'].' to '.$filters['to'],
        (bool) $filters['from'] => 'from '.$filters['from'],
        (bool) $filters['to'] => 'up to '.$filters['to'],
        default => 'all dates',
    };
    $query = array_filter($filters);   // keeps the chosen dates on the links
    $rate = $metrics['resolution_rate'];
    $rateBase = $metrics['total'] - $metrics['cancelled'];

    $whyStyle = [
        'overdue' => 'color:var(--red);border-color:var(--red);background:var(--red-dim)',
        'unassigned' => 'color:#8a4a06;border-color:#f1d78f;background:var(--amber-dim)',
        'stale' => 'color:var(--text-2);border-color:var(--line);background:var(--surface-2)',
    ];
    $whyIcon = ['overdue' => 'fa-clock', 'unassigned' => 'fa-user-slash', 'stale' => 'fa-hourglass-half'];
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Reports</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('error') }}</div></div>
        @endif
        @if ($errors->assign->any())
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $errors->assign->first() }}</div></div>
        @endif

        <form method="GET" action="{{ route('reports.index') }}" class="card">
            @php
            $today = now()->toDateString();
            $presets = [
                '1 week'   => [now()->subDays(6)->toDateString(), $today],
                '1 month'  => [now()->subMonthNoOverflow()->addDay()->toDateString(), $today],
                '3 months' => [now()->subMonthsNoOverflow(3)->addDay()->toDateString(), $today],
                '6 months' => [now()->subMonthsNoOverflow(6)->addDay()->toDateString(), $today],
                '1 year'   => [now()->subYearNoOverflow()->addDay()->toDateString(), $today],
            ];
        @endphp
        <div class="p6-bar" style="margin-top:0;margin-bottom:14px">
            <span style="font-size:12.5px;font-weight:700;color:var(--text-3)">Quick range:</span>
            @foreach ($presets as $label => [$pFrom, $pTo])
                <a href="{{ route('reports.index', ['from' => $pFrom, 'to' => $pTo]) }}"
                   class="btn btn-sm {{ ($filters['from'] === $pFrom && $filters['to'] === $pTo) ? 'btn-primary' : '' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('reports.index') }}"
               class="btn btn-sm {{ (! $filters['from'] && ! $filters['to']) ? 'btn-primary' : '' }}">All time</a>
        </div>

        <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="from">From (submitted)</label>
                    <input id="from" type="date" name="from" value="{{ $filters['from'] }}">
                    @error('from')<div class="field-err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="to">To (submitted)</label>
                    <input id="to" type="date" name="to" value="{{ $filters['to'] }}">
                    @error('to')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="{{ route('reports.index') }}" class="btn">Clear</a>
                @if ($canExport)
                    <a href="{{ route('reports.export', $query) }}" class="btn btn-ok push"><i class="fa-solid fa-file-csv"></i> Download CSV</a>
                @endif
            </div>
        </form>

        <p class="p6-note">Showing tickets submitted <b>{{ $range }}</b>. Only tickets you are allowed to see are counted.</p>

        {{-- headline numbers --}}
        <div class="stats">
            <div class="stat" style="--c:var(--blue)">
                <div class="k">Tickets</div>
                <div class="v">{{ $metrics['total'] }}</div>
                <span class="ic"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div class="stat" style="--c:var(--red)">
                <div class="k">Emergency</div>
                <div class="v red">{{ $metrics['emergency'] }}</div>
                <span class="ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
            </div>
            <div class="stat" style="--c:var(--gold)">
                <div class="k">Overdue now</div>
                <div class="v amber">{{ $metrics['overdue'] }}</div>
                <span class="ic"><i class="fa-solid fa-clock"></i></span>
            </div>
            <div class="stat" style="--c:var(--deep)">
                <div class="k">Backlog</div>
                <div class="v">{{ $metrics['backlog'] }}</div>
                <div class="s">still open (not completed)</div>
                <span class="ic"><i class="fa-solid fa-inbox"></i></span>
            </div>
            <div class="stat" style="--c:var(--green)">
                <div class="k">Resolution rate</div>
                <div class="v">{{ $rate === null ? '—' : number_format($rate, 1).'%' }}</div>
                <div class="s">{{ $metrics['resolved'] }} of {{ $rateBase }} resolved @if ($metrics['cancelled'] > 0) · {{ $metrics['cancelled'] }} cancelled not counted @endif</div>
                <span class="ic"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div class="stat" style="--c:var(--blue)">
                <div class="k">Avg. first response</div>
                <div class="v">{{ $hours($metrics['avg_first_response_hours']) }}</div>
                <div class="s">{{ $metrics['first_response_count'] }} of {{ $metrics['total'] }} answered</div>
                <span class="ic"><i class="fa-solid fa-reply"></i></span>
            </div>
            <div class="stat" style="--c:var(--deep)">
                <div class="k">Avg. time to approve</div>
                <div class="v">{{ $hours($metrics['avg_approval_hours']) }}</div>
                <span class="ic"><i class="fa-solid fa-stamp"></i></span>
            </div>
            <div class="stat" style="--c:var(--green)">
                <div class="k">Avg. time to complete</div>
                <div class="v">{{ $hours($metrics['avg_completion_hours']) }}</div>
                <span class="ic"><i class="fa-solid fa-flag-checkered"></i></span>
            </div>
        </div>

        {{-- Needs attention: about NOW, so the dates above do not apply. Only tickets this user may see. --}}
        <section class="card" id="attention">
            <div class="card-h">
                <h3>Needs attention
                    <span class="badge" style="margin-left:8px;color:var(--red);border-color:var(--red);background:var(--red-dim)">{{ $attention['total'] }}</span>
                </h3>
            </div>
            <p class="p6-note" style="margin:0 0 12px">
                Open tickets that are overdue, have nobody assigned, or had no activity for {{ (int) config('school.reports.stale_days', 3) }} days.
                This list is about <b>right now</b>, so the dates above do not change it.
                @if ($attention['total'] > count($attention['items']))
                    Showing the {{ count($attention['items']) }} most urgent of {{ $attention['total'] }}.
                @endif
            </p>

            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Why</th>
                            <th>Status</th>
                            <th>Assigned to</th>
                            <th>{{ $canAssign ? 'Quick assign' : '' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($attention['items'] as $item)
                            @php $t = $item['ticket']; @endphp
                            <tr>
                                <td data-label="Ticket">
                                    <a href="{{ route('tickets.show', $t) }}"><b style="color:var(--navy)">{{ $t->ticket_no }}</b></a>
                                    @if ($t->is_emergency)
                                        <span class="badge" style="background:var(--red);border-color:var(--red);color:#fff;font-weight:800"><i class="fa-solid fa-triangle-exclamation"></i> Emergency</span>
                                    @endif
                                    @if ($t->is_confidential)
                                        <span class="badge st-closed" style="font-weight:800"><i class="fa-solid fa-lock"></i> Confidential</span>
                                    @endif
                                    <div class="hint" style="margin:2px 0 0">{{ $t->category?->name ?? '—' }} · {{ $t->location }}</div>
                                </td>
                                <td data-label="Why">
                                    <div class="p6-why">
                                        @foreach ($item['reasons'] as $reason)
                                            <span class="badge" style="{{ $whyStyle[$reason['key']] }}"><i class="fa-solid {{ $whyIcon[$reason['key']] }}"></i> {{ $reason['label'] }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td data-label="Status"><span class="badge {{ $t->status->badgeClass() }}">{{ $t->status->label() }}</span></td>
                                <td data-label="Assigned to">{{ $t->assignee?->name ?? 'Not assigned' }}</td>
                                <td data-label="{{ $canAssign ? 'Quick assign' : '' }}">
                                    @if ($canAssign && $item['unassigned'])
                                        <form method="POST" action="{{ route('tickets.assign', $t) }}" class="p6-qa">
                                            @csrf
                                            <input type="hidden" name="return" value="reports">
                                            @if ($filters['from'])<input type="hidden" name="from" value="{{ $filters['from'] }}">@endif
                                            @if ($filters['to'])<input type="hidden" name="to" value="{{ $filters['to'] }}">@endif
                                            <select name="assignee_id" required aria-label="Assign {{ $t->ticket_no }} to">
                                                <option value="">Choose staff…</option>
                                                @foreach ($staff as $member)
                                                    <option value="{{ $member->id }}">{{ $member->name }} — {{ $member->open_tickets_count }} open</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-primary" type="submit">Assign</button>
                                        </form>
                                    @else
                                        <a href="{{ route('tickets.show', $t) }}" class="btn">Open ticket</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty"><i class="fa-regular fa-circle-check"></i>Nothing needs attention right now.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- charts: Chart.js comes from the Vite bundle (resources/js/reports.js), never from a CDN --}}
        <div id="report-charts" data-charts="{{ json_encode($charts) }}" class="p6-charts">
            @foreach ([
                'status' => 'Tickets per status',
                'category' => 'Tickets per category (all vs still open)',
                'monthly' => 'Tickets per month',
                'department' => 'Tickets per department',
                'daily' => 'Tickets per day (last '.(int) config('school.reports.daily_days', 30).' days of the range)',
            ] as $key => $title)
                <section data-chart-card class="card {{ $key === 'daily' ? 'p6-wide' : '' }}">
                    <div class="card-h"><h3>{{ $title }}</h3></div>
                    <div class="p6-chart-box">
                        <canvas id="chart-{{ $key }}" role="img" aria-label="{{ $title }}"></canvas>
                    </div>
                    <p data-empty class="p6-empty hidden"><i class="fa-regular fa-chart-bar" style="display:block;font-size:28px;margin-bottom:8px;opacity:.5"></i>No tickets in this range.</p>
                </section>
            @endforeach
        </div>

        <section class="card">
            <div class="card-h"><h3>Workload per assigned staff</h3></div>
            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th class="p6-num">Assigned</th>
                            <th class="p6-num">Still open</th>
                            <th class="p6-num">Overdue</th>
                            <th class="p6-num">Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($metrics['workload'] as $row)
                            <tr>
                                <td data-label="Staff"><b style="color:var(--navy)">{{ $row['name'] }}</b></td>
                                <td data-label="Assigned" class="p6-num">{{ $row['assigned'] }}</td>
                                <td data-label="Still open" class="p6-num">{{ $row['open'] }}</td>
                                <td data-label="Overdue" class="p6-num">{{ $row['overdue'] }}</td>
                                <td data-label="Completed" class="p6-num">{{ $row['completed'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty"><i class="fa-regular fa-folder-open"></i>No assigned tickets in this range.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
