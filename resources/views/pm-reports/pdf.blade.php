@php
    $rate = $summary['rate'];
    $pct = fn ($r) => $r === null ? '—' : number_format($r, 1).'%';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PM Compliance Report</title>
<style>
    @page { margin: 26px 30px 38px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1B3355; }
    h1 { font-size: 18px; margin: 0; }
    h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #C9A227; }
    .sub { color: #5b6b82; font-size: 9px; margin-top: 3px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #E7EFF9; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; }
    td { padding: 4px 6px; border-bottom: 1px solid #DCE6F2; vertical-align: top; }
    .r { text-align: right; }
    .kpi td { border: 1px solid #DCE6F2; text-align: center; padding: 8px 4px; width: 16.6%; }
    .kpi .v { font-size: 17px; font-weight: bold; }
    .kpi .k { font-size: 8px; color: #5b6b82; text-transform: uppercase; }
    .muted { color: #5b6b82; }
    .bad { color: #B3261E; font-weight: bold; }
    .foot { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #5b6b82; text-align: center; }
    .pn:after { content: counter(page); }
</style>
</head>
<body>
<div class="foot">LCCT Maintenance Ticketing System · Page <span class="pn"></span></div>

<table style="margin-bottom:6px"><tr>
    @if ($logo)<td style="border:0;width:52px;padding:0"><img src="{{ $logo }}" style="width:46px;height:46px"></td>@endif
    <td style="border:0;padding:0">
        <h1>Preventive Maintenance Compliance Report</h1>
        <div class="sub">La Consolacion College Tanauan · Maintenance Ticketing System</div>
        <div class="sub">PM due: <b>{{ $range }}</b>@if ($facilityName) · Facility: <b>{{ $facilityName }}</b>@endif · Generated {{ now()->format('M j, Y g:i A') }} by {{ $generatedBy }}</div>
    </td>
</tr></table>

<table class="kpi"><tr>
    <td><div class="v">{{ $pct($rate) }}</div><div class="k">On-time rate</div></td>
    <td><div class="v">{{ $summary['on_time'] }}</div><div class="k">Done on time</div></td>
    <td><div class="v">{{ $summary['late'] }}</div><div class="k">Done late</div></td>
    <td><div class="v">{{ $summary['overdue'] }}</div><div class="k">Overdue now</div></td>
    <td><div class="v">{{ $summary['open'] }}</div><div class="k">Not due yet</div></td>
    <td><div class="v">{{ $summary['cancelled'] }}</div><div class="k">Cancelled</div></td>
</tr></table>
<div class="sub">Rate = done on or before the PM due date, out of everything done or overdue. Not-due-yet and cancelled tickets are not counted.@if ($summary['avg_days_late'] !== null) Late work was done {{ number_format($summary['avg_days_late'], 1) }} days late on average.@endif</div>

@if ($rowsTotal === 0)
    <h2>PM tickets</h2>
    <p class="muted">No PM tickets are due in this range.</p>
@else
    @if ($overdueTotal > 0)
        <h2>Overdue now ({{ $overdueTotal }})</h2>
        <table>
            <thead><tr><th>Ticket</th><th>PM schedule</th><th>Facility</th><th>Assigned to</th><th>Due</th><th class="r">Days overdue</th></tr></thead>
            <tbody>
                @foreach ($overdue as $r)
                    <tr>
                        <td><b>{{ $r['ticket_no'] }}</b></td>
                        <td>{{ $r['schedule'] }}</td>
                        <td>{{ $r['facility'] }}</td>
                        <td>{{ $r['assignee'] ?? 'Unassigned' }}</td>
                        <td>{{ \Carbon\Carbon::parse($r['due'])->format('M j, Y') }}</td>
                        <td class="r bad">{{ $r['days'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($overdueTotal > count($overdue))<div class="sub">Showing {{ count($overdue) }} of {{ $overdueTotal }}.</div>@endif
    @endif

    @foreach ([['By facility', $byFacility, 'Facility'], ['By month (PM due date)', $byMonth, 'Month']] as [$heading, $groups, $first])
        <h2>{{ $heading }}</h2>
        <table>
            <thead><tr><th>{{ $first }}</th><th class="r">On time</th><th class="r">Late</th><th class="r">Overdue</th><th class="r">Not due yet</th><th class="r">Cancelled</th><th class="r">On-time rate</th></tr></thead>
            <tbody>
                @foreach ($groups as $g)
                    <tr>
                        <td><b>{{ $g['label'] }}</b></td>
                        <td class="r">{{ $g['on_time'] }}</td>
                        <td class="r">{{ $g['late'] }}</td>
                        <td class="r">{{ $g['overdue'] }}</td>
                        <td class="r">{{ $g['open'] }}</td>
                        <td class="r">{{ $g['cancelled'] }}</td>
                        <td class="r"><b>{{ $pct($g['rate']) }}</b></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <h2>PM tickets ({{ $rowsTotal }})</h2>
    <table>
        <thead><tr><th>Ticket</th><th>PM schedule</th><th>Facility</th><th>Technician</th><th>Due</th><th>Done</th><th>Result</th></tr></thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td><b>{{ $r['ticket_no'] }}</b></td>
                    <td>{{ $r['schedule'] }}</td>
                    <td>{{ $r['facility'] }}</td>
                    <td>{{ $r['technician'] ?? '—' }}</td>
                    <td>{{ \Carbon\Carbon::parse($r['due'])->format('M j, Y') }}</td>
                    <td>{{ $r['done'] ? \Carbon\Carbon::parse($r['done'])->format('M j, Y') : '—' }}</td>
                    <td>{{ $labels[$r['bucket']] }}@if (in_array($r['bucket'], ['late', 'overdue'], true)) ({{ $r['days'] }} {{ \Illuminate\Support\Str::plural('day', $r['days']) }})@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($rowsTotal > count($rows))<div class="sub">Showing the latest {{ count($rows) }} of {{ $rowsTotal }} tickets. The CSV has all of them.</div>@endif
@endif
</body>
</html>
