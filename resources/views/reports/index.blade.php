@php
    $hours = fn ($h) => $h === null ? '—' : ($h >= 48 ? number_format($h / 24, 1).' days' : number_format($h, 1).' h');
    $range = match (true) {
        $filters['from'] && $filters['to'] => $filters['from'].' to '.$filters['to'],
        (bool) $filters['from'] => 'from '.$filters['from'],
        (bool) $filters['to'] => 'up to '.$filters['to'],
        default => 'all dates',
    };
    $query = array_filter($filters);   // keeps the chosen dates on the links
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Reports</h2>
    </x-slot>

    <div class="content p6-wrap">

        <form method="GET" action="{{ route('reports.index') }}" class="card">
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
                <div class="k">Avg. time to approve</div>
                <div class="v">{{ $hours($metrics['avg_approval_hours']) }}</div>
                <span class="ic"><i class="fa-solid fa-stamp"></i></span>
            </div>
            <div class="stat" style="--c:var(--green)">
                <div class="k">Avg. time to complete</div>
                <div class="v">{{ $hours($metrics['avg_completion_hours']) }}</div>
                <span class="ic"><i class="fa-solid fa-circle-check"></i></span>
            </div>
        </div>

        {{-- charts: Chart.js comes from the Vite bundle (resources/js/reports.js), never from a CDN --}}
        <div id="report-charts" data-charts="{{ json_encode($charts) }}" class="p6-charts">
            @foreach ([
                'status' => 'Tickets per status',
                'category' => 'Tickets per category',
                'monthly' => 'Tickets per month',
                'department' => 'Tickets per department',
            ] as $key => $title)
                <section data-chart-card class="card">
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
                            <th class="p6-num">Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($metrics['workload'] as $row)
                            <tr>
                                <td data-label="Staff"><b style="color:var(--navy)">{{ $row['name'] }}</b></td>
                                <td data-label="Assigned" class="p6-num">{{ $row['assigned'] }}</td>
                                <td data-label="Still open" class="p6-num">{{ $row['open'] }}</td>
                                <td data-label="Completed" class="p6-num">{{ $row['completed'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="empty"><i class="fa-regular fa-folder-open"></i>No assigned tickets in this range.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
