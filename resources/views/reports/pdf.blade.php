@php
    $hours = fn ($h) => $h === null ? '—' : ($h < 1 ? round($h * 60).' min' : ($h >= 48 ? number_format($h / 24, 1).' days' : number_format($h, 1).' h'));
    $rate = $metrics['resolution_rate'];
    $bars = function (array $rows, string $color = '#1B3355') {
        $max = max(1, ...array_merge([1], array_column($rows, 'count')));
        return [$rows, $max, $color];
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Maintenance Report</title>
<style>
    @page { margin: 26px 30px 38px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1B3355; }
    h1 { font-size: 18px; margin: 0; }
    h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #C9A227; }
    .sub { color: #5b6b82; font-size: 9px; margin-top: 3px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #E7EFF9; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; }
    td { padding: 4px 6px; border-bottom: 1px solid #DCE6F2; vertical-align: top; }
    .num { text-align: right; width: 48px; }
    .kpi td { border: 1px solid #DCE6F2; text-align: center; padding: 8px 4px; width: 12.5%; }
    .kpi .v { font-size: 17px; font-weight: bold; }
    .kpi .k { font-size: 8px; color: #5b6b82; text-transform: uppercase; }
    .barbg { background: #E7EFF9; height: 8px; }
    .bar { height: 8px; background: #1B3355; }
    .two td.col { width: 50%; border: 0; padding: 0 10px 0 0; }
    .muted { color: #5b6b82; }
    .foot { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #5b6b82; text-align: center; }
    .pn:after { content: counter(page); }
    .em { color: #B3261E; font-weight: bold; }
</style>
</head>
<body>
<div class="foot">LCCT Maintenance Ticketing System · Page <span class="pn"></span></div>

<table style="margin-bottom:6px"><tr>
    @if ($logo)<td style="border:0;width:52px;padding:0"><img src="{{ $logo }}" style="width:46px;height:46px"></td>@endif
    <td style="border:0;padding:0">
        <h1>Maintenance Ticket Report</h1>
        <div class="sub">La Consolacion College Tanauan · Maintenance Ticketing System</div>
        <div class="sub">Tickets submitted: <b>{{ $range }}</b> · Generated {{ now()->format('M j, Y g:i A') }} by {{ $generatedBy }}</div>
    </td>
</tr></table>

<table class="kpi"><tr>
    <td><div class="v">{{ $metrics['total'] }}</div><div class="k">Tickets</div></td>
    <td><div class="v">{{ $metrics['emergency'] }}</div><div class="k">Emergency</div></td>
    <td><div class="v">{{ $metrics['overdue'] }}</div><div class="k">Overdue now</div></td>
    <td><div class="v">{{ $metrics['backlog'] }}</div><div class="k">Backlog</div></td>
    <td><div class="v">{{ $rate === null ? '—' : $rate.'%' }}</div><div class="k">Resolution rate</div></td>
    <td><div class="v">{{ $hours($metrics['avg_first_response_hours']) }}</div><div class="k">Avg first response</div></td>
    <td><div class="v">{{ $hours($metrics['avg_approval_hours']) }}</div><div class="k">Avg time to approve</div></td>
    <td><div class="v">{{ $hours($metrics['avg_completion_hours']) }}</div><div class="k">Avg time to complete</div></td>
</tr></table>

@foreach ([
    ['Tickets per status', $metrics['by_status']],
    ['Tickets per category', $metrics['by_category']],
    ['Tickets per department', $metrics['by_department']],
    ['Tickets per month', $metrics['monthly']],
] as [$title, $rows])
    @php $max = max(1, ...array_merge([1], array_column($rows, 'count'))); @endphp
    <h2>{{ $title }}</h2>
    @if (count($rows) === 0 || array_sum(array_column($rows, 'count')) === 0)
        <div class="muted">No tickets in this range.</div>
    @else
        <table>
            @foreach ($rows as $row)
                @if ($row['count'] > 0)
                    <tr>
                        <td style="width:170px">{{ $row['label'] }}</td>
                        <td class="num"><b>{{ $row['count'] }}</b></td>
                        <td><div class="barbg"><div class="bar" style="width:{{ round($row['count'] / $max * 100) }}%"></div></div></td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif
@endforeach

<h2>Workload per assigned staff</h2>
@if (count($metrics['workload']) === 0)
    <div class="muted">No assigned tickets in this range.</div>
@else
    <table>
        <tr><th>Staff</th><th class="num">Assigned</th><th class="num">Open</th><th class="num">Overdue</th><th class="num">Completed</th></tr>
        @foreach ($metrics['workload'] as $w)
            <tr><td>{{ $w['name'] }}</td><td class="num">{{ $w['assigned'] }}</td><td class="num">{{ $w['open'] }}</td><td class="num">{{ $w['overdue'] }}</td><td class="num">{{ $w['completed'] }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Ticket list</h2>
@if ($tickets->isEmpty())
    <div class="muted">No tickets in this range.</div>
@else
    <table>
        <tr><th>Ticket</th><th>Status</th><th>Category</th><th>Department</th><th>Requester</th><th>Assigned to</th><th>Submitted</th><th>Completed</th></tr>
        @foreach ($tickets as $t)
            <tr>
                <td><b>{{ $t->ticket_no }}</b>@if ($t->is_emergency) <span class="em">EMERGENCY</span>@endif</td>
                <td>{{ $t->status->label() }}</td>
                <td>{{ $t->category?->name ?? '—' }}</td>
                <td>{{ $t->department?->name ?? '—' }}</td>
                <td>{{ $t->requester?->name ?? '—' }}</td>
                <td>{{ $t->assignee?->name ?? 'Not assigned' }}</td>
                <td>{{ $t->submitted_at?->format('Y-m-d H:i') }}</td>
                <td>{{ $t->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
    @if ($totalTickets > $tickets->count())
        <div class="muted" style="margin-top:6px">Showing the newest {{ $tickets->count() }} of {{ $totalTickets }} tickets. Choose a shorter range to see the rest.</div>
    @endif
@endif
</body>
</html>