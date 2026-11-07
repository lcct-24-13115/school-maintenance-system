{{-- PM E4: maintenance calendar. A month grid plus the same month as a list. Plain HTML: no JavaScript, no npm package. --}}
@php
    // links that keep the chosen filters
    $link = fn (string $month) => route('maintenance-calendar.index', array_filter(
        ['month' => $month] + $filters,
        fn ($v) => $v !== null && $v !== ''
    ));
    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $hasFilter = count(array_filter($filters, fn ($v) => $v !== null && $v !== '')) > 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">Maintenance calendar</h2>
    </x-slot>

    <style>
        .pmc-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
        .pmc-nav h3{font-family:var(--font-display);font-size:22px;font-weight:600;color:var(--navy);margin:0 8px;min-width:170px;text-align:center}
        .pmc-scroll{overflow-x:auto}
        .pmc-grid{display:grid;grid-template-columns:repeat(7,minmax(104px,1fr));min-width:760px;border-top:1px solid var(--line);border-left:1px solid var(--line)}
        .pmc-h{padding:8px 6px;text-align:center;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--text-2);background:var(--surface-2);border-right:1px solid var(--line);border-bottom:1px solid var(--line)}
        .pmc-day{min-height:104px;padding:5px;background:#fff;border-right:1px solid var(--line);border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:3px;min-width:0}
        .pmc-day.out{background:#f6f8fb}
        .pmc-day.out .pmc-n{color:var(--text-3)}
        .pmc-day.today{box-shadow:inset 0 0 0 2px var(--blue)}
        .pmc-n{font-size:12.5px;font-weight:800;color:var(--navy)}
        .pmc-ev{display:block;padding:2px 6px;border-radius:7px;font-size:12px;font-weight:700;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
        a.pmc-ev:hover{filter:brightness(.92)}
        .pmc-more summary{cursor:pointer;font-size:12px;font-weight:700;color:var(--deep);list-style:none}
        .pmc-more summary::-webkit-details-marker{display:none}
        .pmc-more[open] summary{margin-bottom:3px}
        .pmc-legend{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
        .pmc-chip{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:999px;font-size:12.5px;font-weight:700;background:#fff;border:1px solid var(--line);color:var(--text-2)}
        .pmc-chip i{display:inline-block;width:11px;height:11px;border-radius:50%}
        .pmc-chip b{color:var(--navy)}
        .pmc-st{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:800}
        @@media(max-width:575.98px){.pmc-nav h3{min-width:0;flex:1 1 100%;order:-1;margin:0 0 4px}}
    </style>

    <div class="content p6-wrap">
        <x-help-link topic="Preventive maintenance" row />

        <form method="GET" action="{{ route('maintenance-calendar.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="month">Month</label>
                    <input id="month" type="month" name="month" value="{{ $monthKey }}">
                    @error('month')<div class="field-err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="facility_id">Facility</label>
                    <select id="facility_id" name="facility_id">
                        <option value="">All facilities</option>
                        @foreach ($facilities as $f)
                            <option value="{{ $f->id }}" @selected($filters['facility_id'] === $f->id)>{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="equipment_id">Equipment</label>
                    <select id="equipment_id" name="equipment_id">
                        <option value="">All equipment</option>
                        @foreach ($equipmentList as $q)
                            <option value="{{ $q->id }}" @selected($filters['equipment_id'] === $q->id)>{{ $q->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="">All categories</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected($filters['category_id'] === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="assignee_id">Assigned to</label>
                    <select id="assignee_id" name="assignee_id">
                        <option value="">Anyone</option>
                        @foreach ($assignees as $a)
                            <option value="{{ $a->id }}" @selected($filters['assignee_id'] === $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $key => $s)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $s['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
                @if ($hasFilter)
                    <a href="{{ route('maintenance-calendar.index', ['month' => $monthKey]) }}" class="btn">Clear filters</a>
                @endif
                <a href="{{ route('admin.pm-schedules.index') }}" class="btn push"><i class="fa-solid fa-calendar-check"></i> PM schedules</a>
            </div>
        </form>

        <p class="p6-note" style="margin-top:14px">
            Blue dates are <b>coming up</b>: the ticket is not made yet (it is made a few days before the due date). Tickets that already exist show their real status.
            A click on a ticket opens it.
        </p>

        <div class="pmc-nav">
            <a href="{{ $link($prevKey) }}" class="btn" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i> Previous</a>
            <h3>{{ $monthLabel }}</h3>
            <a href="{{ $link($nextKey) }}" class="btn" aria-label="Next month">Next <i class="fa-solid fa-chevron-right"></i></a>
            @if ($monthKey !== $thisKey)
                <a href="{{ $link($thisKey) }}" class="btn btn-ok push">This month</a>
            @endif
        </div>

        <div class="pmc-legend">
            @foreach ($statuses as $key => $s)
                <span class="pmc-chip"><i style="background:{{ $s['color'] }}"></i>{{ $s['label'] }} <b>{{ $counts[$key] }}</b></span>
            @endforeach
        </div>

        <section class="card" style="padding:0">
            <div class="pmc-scroll">
                <div class="pmc-grid">
                    @foreach ($dayNames as $name)
                        <div class="pmc-h">{{ $name }}</div>
                    @endforeach

                    @foreach ($weeks as $week)
                        @foreach ($week as $d)
                            <div class="pmc-day {{ $d['in_month'] ? '' : 'out' }} {{ $d['today'] ? 'today' : '' }}">
                                <div class="pmc-n">{{ $d['day'] }}</div>
                                @foreach (array_slice($d['events'], 0, 3) as $e)
                                    @include('maintenance._calendar_event', ['e' => $e, 'canManage' => $canManage])
                                @endforeach
                                @if (count($d['events']) > 3)
                                    <details class="pmc-more">
                                        <summary>+{{ count($d['events']) - 3 }} more</summary>
                                        @foreach (array_slice($d['events'], 3) as $e)
                                            @include('maintenance._calendar_event', ['e' => $e, 'canManage' => $canManage])
                                        @endforeach
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </section>

        <section class="card" style="padding:0;margin-top:18px">
            <div style="padding:16px 18px 6px">
                <h3 class="p6-card-t" style="margin:0">{{ $monthLabel }} in a list</h3>
            </div>

            @if (count($agenda) === 0)
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-calendar-check"></i>Nothing is planned in this month.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Due</th><th>PM schedule</th><th>Facility / equipment</th><th>Assigned to</th><th>Status</th><th>Ticket</th></tr></thead>
                        <tbody>
                            @foreach ($agenda as $e)
                                @php
                                    $p = $e['extendedProps'];
                                    $st = $statuses[$p['status']];
                                @endphp
                                <tr>
                                    <td data-label="Due">{{ $p['due_date_label'] }}</td>
                                    <td data-label="PM schedule" style="word-break:break-word">
                                        @if ($canManage)
                                            <a href="{{ $p['schedule_url'] }}"><b>{{ $e['title'] }}</b></a>
                                        @else
                                            <b>{{ $e['title'] }}</b>
                                        @endif
                                        <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $p['frequency'] }}</div>
                                    </td>
                                    <td data-label="Facility / equipment">{{ $p['facility'] ?? '—' }}@if ($p['equipment']) <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $p['equipment'] }}</div>@endif</td>
                                    <td data-label="Assigned to">{{ $p['assignee'] }}</td>
                                    <td data-label="Status"><span class="pmc-st" style="background:{{ $st['color'] }};color:{{ $st['text'] }}">{{ $p['status_label'] }}</span></td>
                                    <td data-label="Ticket">
                                        @if ($p['ticket_url'])
                                            <a href="{{ $p['ticket_url'] }}"><b>{{ $p['ticket_no'] }}</b></a>
                                            <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $p['ticket_status'] }}</div>
                                        @else
                                            <span style="color:var(--text-3)">Not made yet</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
