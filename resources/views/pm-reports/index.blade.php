{{-- PM E3: PM compliance report. Dates are PM DUE dates. Only tickets the person may see are counted. --}}
@php
    $query = array_filter($filters);   // keeps the chosen filters on the download links
    $rate = $summary['rate'];
    $tone = fn ($r) => $r === null ? 'var(--text-3)' : ($r >= 90 ? 'var(--green)' : ($r >= 70 ? '#8a4a06' : 'var(--red)'));
    $badge = [
        'on_time' => 'badge nocap ab-ok',
        'late' => 'badge nocap',
        'overdue' => 'badge nocap ab-fail',
        'open' => 'badge nocap',
        'cancelled' => 'badge nocap',
    ];
    $range = match (true) {
        $filters['from'] && $filters['to'] => $filters['from'].' to '.$filters['to'],
        (bool) $filters['from'] => 'from '.$filters['from'],
        (bool) $filters['to'] => 'up to '.$filters['to'],
        default => 'all dates',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">PM compliance report</h2>
    </x-slot>

    <div class="content p6-wrap">

        <form method="GET" action="{{ route('pm-reports.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="from">From (PM due date)</label>
                    <input id="from" type="date" name="from" value="{{ $filters['from'] }}">
                    @error('from')<div class="field-err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="to">To (PM due date)</label>
                    <input id="to" type="date" name="to" value="{{ $filters['to'] }}">
                    @error('to')<div class="field-err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="facility_id">Facility</label>
                    <select id="facility_id" name="facility_id">
                        <option value="">All facilities</option>
                        @foreach ($facilities as $f)
                            <option value="{{ $f->id }}" @selected($filters['facility_id'] === $f->id)>{{ $f->name }}</option>
                        @endforeach
                    </select>
                    @error('facility_id')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="{{ route('pm-reports.index') }}" class="btn">Clear</a>
                <a href="{{ route('pm-reports.export', $query) }}" class="btn push"><i class="fa-solid fa-file-csv"></i> Download CSV</a>
                <a href="{{ route('pm-reports.pdf', $query) }}" class="btn btn-ok"><i class="fa-solid fa-file-pdf"></i> Download PDF</a>
            </div>
        </form>

        <p class="p6-note">
            PM tickets due <b>{{ $range }}</b>. On-time rate = done on or before the due date, out of everything done or overdue.
            Not-due-yet and cancelled tickets are not counted in the rate.
        </p>

        <div class="stats">
            <div class="stat" style="--c:var(--green)">
                <div class="k">On-time rate</div>
                <div class="v" style="color:{{ $tone($rate) }}">{{ $rate === null ? '—' : number_format($rate, 1).'%' }}</div>
                <div class="s">{{ $summary['on_time'] }} of {{ $summary['on_time'] + $summary['late'] + $summary['overdue'] }} counted</div>
                <span class="ic"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div class="stat" style="--c:var(--green)">
                <div class="k">Done on time</div>
                <div class="v">{{ $summary['on_time'] }}</div>
                <span class="ic"><i class="fa-solid fa-check"></i></span>
            </div>
            <div class="stat" style="--c:var(--gold)">
                <div class="k">Done late</div>
                <div class="v amber">{{ $summary['late'] }}</div>
                <div class="s">{{ $summary['avg_days_late'] === null ? 'none' : 'on average '.number_format($summary['avg_days_late'], 1).' days late' }}</div>
                <span class="ic"><i class="fa-solid fa-clock"></i></span>
            </div>
            <div class="stat" style="--c:var(--red)">
                <div class="k">Overdue now</div>
                <div class="v red">{{ $summary['overdue'] }}</div>
                <span class="ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
            </div>
            <div class="stat" style="--c:var(--blue)">
                <div class="k">Not due yet</div>
                <div class="v">{{ $summary['open'] }}</div>
                <div class="s">{{ $summary['due_soon'] }} due in the next 7 days</div>
                <span class="ic"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div class="stat" style="--c:var(--deep)">
                <div class="k">Cancelled</div>
                <div class="v">{{ $summary['cancelled'] }}</div>
                <div class="s">not counted</div>
                <span class="ic"><i class="fa-solid fa-ban"></i></span>
            </div>
        </div>

        @if ($rowsTotal === 0)
            <div class="card"><div class="empty" style="padding:22px 16px"><i class="fa-solid fa-clipboard-check"></i>No PM tickets are due in this range.</div></div>
        @else
            @if ($overdueTotal > 0)
                <section class="card" style="padding:0">
                    <div style="padding:16px 18px 6px">
                        <h3 class="p6-card-t" style="margin:0">Overdue now ({{ $overdueTotal }})</h3>
                        <p class="p6-note" style="margin:6px 0 0">Longest overdue first{{ $overdueTotal > count($overdue) ? '. Showing '.count($overdue).' of '.$overdueTotal.'. The CSV has all of them.' : '' }}</p>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Ticket</th><th>PM schedule</th><th>Facility</th><th>Assigned to</th><th>Due</th><th style="text-align:right">Days overdue</th></tr></thead>
                            <tbody>
                                @foreach ($overdue as $r)
                                    <tr>
                                        <td data-label="Ticket"><a href="{{ route('tickets.show', $r['id']) }}"><b>{{ $r['ticket_no'] }}</b></a></td>
                                        <td data-label="PM schedule" style="word-break:break-word">{{ $r['schedule'] }}</td>
                                        <td data-label="Facility">{{ $r['facility'] }}</td>
                                        <td data-label="Assigned to">{{ $r['assignee'] ?? 'Unassigned' }}</td>
                                        <td data-label="Due">{{ \Carbon\Carbon::parse($r['due'])->format('M j, Y') }}</td>
                                        <td data-label="Days overdue" style="text-align:right"><b>{{ $r['days'] }}</b></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @foreach ([['By facility', $byFacility, 'Facility'], ['By month (PM due date)', $byMonth, 'Month']] as [$heading, $groups, $first])
                <section class="card" style="padding:0">
                    <div style="padding:16px 18px 6px"><h3 class="p6-card-t" style="margin:0">{{ $heading }}</h3></div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ $first }}</th>
                                    <th style="text-align:right">On time</th>
                                    <th style="text-align:right">Late</th>
                                    <th style="text-align:right">Overdue</th>
                                    <th style="text-align:right">Not due yet</th>
                                    <th style="text-align:right">Cancelled</th>
                                    <th style="text-align:right">On-time rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups as $g)
                                    <tr>
                                        <td data-label="{{ $first }}"><b>{{ $g['label'] }}</b></td>
                                        <td data-label="On time" style="text-align:right">{{ $g['on_time'] }}</td>
                                        <td data-label="Late" style="text-align:right">{{ $g['late'] }}</td>
                                        <td data-label="Overdue" style="text-align:right">{{ $g['overdue'] }}</td>
                                        <td data-label="Not due yet" style="text-align:right">{{ $g['open'] }}</td>
                                        <td data-label="Cancelled" style="text-align:right">{{ $g['cancelled'] }}</td>
                                        <td data-label="On-time rate" style="text-align:right;color:{{ $tone($g['rate']) }}"><b>{{ $g['rate'] === null ? '—' : number_format($g['rate'], 1).'%' }}</b></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            <section class="card" style="padding:0">
                <div style="padding:16px 18px 6px">
                    <h3 class="p6-card-t" style="margin:0">PM tickets</h3>
                    <p class="p6-note" style="margin:6px 0 0">Latest due date first{{ $rowsTotal > count($rows) ? '. Showing '.count($rows).' of '.$rowsTotal.'. The CSV has all of them.' : '' }}</p>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ticket</th><th>PM schedule</th><th>Facility</th><th>Technician</th><th>Due</th><th>Done</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td data-label="Ticket"><a href="{{ route('tickets.show', $r['id']) }}"><b>{{ $r['ticket_no'] }}</b></a></td>
                                    <td data-label="PM schedule" style="word-break:break-word">{{ $r['schedule'] }}</td>
                                    <td data-label="Facility">{{ $r['facility'] }}</td>
                                    <td data-label="Technician">{{ $r['technician'] ?? '—' }}</td>
                                    <td data-label="Due">{{ \Carbon\Carbon::parse($r['due'])->format('M j, Y') }}</td>
                                    <td data-label="Done">{{ $r['done'] ? \Carbon\Carbon::parse($r['done'])->format('M j, Y') : '—' }}</td>
                                    <td data-label="Result">
                                        <span class="{{ $badge[$r['bucket']] }}">{{ $labels[$r['bucket']] }}</span>
                                        @if (in_array($r['bucket'], ['late', 'overdue'], true))<div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $r['days'] }} {{ \Illuminate\Support\Str::plural('day', $r['days']) }}</div>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
