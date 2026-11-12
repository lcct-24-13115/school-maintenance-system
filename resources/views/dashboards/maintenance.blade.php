{{-- Maintenance dashboard for Staff, Head and Maintenance Admin. Data: see DashboardController@maintenance. --}}
@php
    $user = auth()->user();

    // hours -> "35 min", "5.5 h", "2 d 4 h"
    $fmt = function (?float $h): string {
        if ($h === null) { return '—'; }
        if ($h < 1) { return max(1, (int) round($h * 60)).' min'; }
        if ($h < 48) { return rtrim(rtrim(number_format($h, 1), '0'), '.').' h'; }
        $d = (int) floor($h / 24);
        $r = (int) round($h - $d * 24);
        return $d.' d'.($r ? ' '.$r.' h' : '');
    };

    $look = [
        'Urgent' => ['fa-triangle-exclamation', 'var(--red)'],
        'Overdue' => ['fa-clock', 'var(--amber)'],
        'Unassigned' => ['fa-user-slash', 'var(--gold)'],
        'Assigned to me' => ['fa-user-check', 'var(--deep)'],
    ];

    // trend chart geometry (viewBox 640 x 220)
    $W = 640; $H = 220; $L = 36; $R = 12; $T = 14; $B = 28;
    $n = count($trend);
    $counts = array_column($trend, 'count');
    $top = max(4, (int) (max($counts ?: [0])));
    if ($top % 2) { $top++; }
    $px = fn (int $i) => $n > 1 ? $L + $i * (($W - $L - $R) / ($n - 1)) : $L;
    $py = fn (int $v) => $T + ($H - $T - $B) * (1 - $v / $top);
    $pts = [];
    foreach ($trend as $i => $row) { $pts[] = round($px($i), 1).','.round($py($row['count']), 1); }
    $line = $pts ? 'M'.implode(' L', $pts) : '';
    $area = $pts ? $line.' L'.round($px($n - 1), 1).','.($H - $B).' L'.round($px(0), 1).','.($H - $B).' Z' : '';
    $trendTotal = array_sum($counts);

    $what = function ($log): string {
        $s = fn ($v) => $v ? (\App\Enums\TicketStatus::tryFrom((string) $v)?->label() ?? $v) : null;

        return match ($log->action) {
            'status_change' => 'Status: '.($s($log->from_status) ?? 'new').' → '.($s($log->to_status) ?? '—'),
            'assignment' => 'Ticket assigned',
            'reassignment' => 'Ticket reassigned',
            'emergency_flag' => 'Emergency flag changed',
            'authorization' => 'Authorization recorded',
            'attachment' => 'File added',
            default => ucfirst(str_replace('_', ' ', (string) $log->action)),
        };
    };
@endphp

<x-app-layout>
    <x-slot name="header">{{ $title }}</x-slot>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ @filemtime(public_path('css/dashboard.css')) }}">

    <section class="hero" style="--hero:url('{{ asset('images/campus.jpg') }}')">
        <div class="hero-in">
            <div class="hero-txt">
                <span class="hero-eye">{{ $title }}</span>
                <h2>Hi {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}, welcome back</h2>
                <p>Signed in as <b>{{ $user->role->name }}</b>. Start with what needs attention, then work down the priority queue.</p>
                <div class="hero-act">
                    <a href="{{ route('maintenance.board') }}" class="btn btn-primary"><i class="fa-solid fa-table-columns"></i> Task board</a>
                    <a href="{{ route('tickets.index') }}" class="btn"><i class="fa-solid fa-list-check"></i> View tickets</a>
                </div>
            </div>

            <div class="hero-last">
                <span class="hl-k"><i class="fa-regular fa-clock"></i> Last update</span>
                <b>{{ $lastUpdate ? $lastUpdate->format('M j, Y g:i A') : '—' }}</b>
                <small>{{ $lastUpdate ? $lastUpdate->diffForHumans() : 'No tickets yet' }}</small>
            </div>
        </div>
    </section>

    {{-- Needs attention --}}
    <h2 class="dash-sec"><i class="fa-solid fa-bolt"></i> Needs attention</h2>
    <div class="stats">
        @foreach ($attention as $label => $card)
            @php [$icon, $colour] = $look[$label] ?? ['fa-ticket', 'var(--blue)']; @endphp
            <a href="{{ $card['url'] }}" class="stat {{ $card['count'] === 0 ? 'is-zero' : '' }}" style="--c:{{ $colour }};text-decoration:none">
                <span class="ic"><i class="fa-solid {{ $icon }}"></i></span>
                <div class="k">{{ $label }}</div>
                <div class="v nums">{{ $card['count'] }}</div>
                <div class="s">View list <i class="fa-solid fa-arrow-right"></i></div>
            </a>
        @endforeach
    </div>

    {{-- Priority queue --}}
    <div class="card">
        <div class="card-h">
            <h3>Priority queue</h3>
            <a class="more" href="{{ route('tickets.index') }}">All tickets <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        @if ($queue->isEmpty())
            <div class="empty"><i class="fa-solid fa-circle-check"></i>No active tickets. Nice work!</div>
        @else
            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr><th>Ticket</th><th>Location / category</th><th>Status</th><th>Assigned to</th><th>Target date</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($queue as $t)
                            <tr>
                                <td data-label="Ticket">
                                    <a href="{{ route('tickets.show', $t) }}" style="font-weight:800">{{ $t->ticket_no }}</a>
                                    <span class="pq-desc">{{ \Illuminate\Support\Str::limit($t->description, 60) }}</span>
                                </td>
                                <td data-label="Location">{{ $t->headline() }}<div class="sub">{{ $t->category?->name }}</div></td>
                                <td data-label="Status"><x-status-badge :status="$t->status" /></td>
                                <td data-label="Assigned to">{{ $t->assignee?->name ?? 'Unassigned' }}</td>
                                <td data-label="Target date">
                                    <div class="pq-badges"><x-ticket-badges :ticket="$t" /></div>
                                    <span class="pq-due">{{ $t->due_at ? $t->due_at->format('M j, Y g:i A') : 'No target date' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Trend + service levels --}}
    <div class="dash-2">
        <div class="card trend">
            <div class="card-h"><h3>Ticket trend · last 30 days</h3><small>Tickets submitted per day</small></div>

            @if ($n > 0)
                <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Tickets submitted per day over the last 30 days: {{ $trendTotal }} in total">
                    @foreach ([0, $top / 2, $top] as $g)
                        <line class="tr-grid" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ round($py((int) $g), 1) }}" y2="{{ round($py((int) $g), 1) }}"/>
                        <text x="{{ $L - 8 }}" y="{{ round($py((int) $g), 1) + 4 }}" text-anchor="end">{{ (int) $g }}</text>
                    @endforeach

                    <path class="tr-area" d="{{ $area }}"/>
                    <path class="tr-line" d="{{ $line }}"/>

                    @foreach ($trend as $i => $row)
                        <circle class="tr-dot" cx="{{ round($px($i), 1) }}" cy="{{ round($py($row['count']), 1) }}" r="{{ $row['count'] > 0 ? 3.5 : 2 }}">
                            <title>{{ $row['label'] }}: {{ $row['count'] }} {{ $row['count'] === 1 ? 'ticket' : 'tickets' }}</title>
                        </circle>
                    @endforeach

                    @foreach ([0, (int) floor(($n - 1) / 2), $n - 1] as $k => $i)
                        <text x="{{ round($px($i), 1) }}" y="{{ $H - 8 }}" text-anchor="{{ $k === 0 ? 'start' : ($k === 2 ? 'end' : 'middle') }}">{{ $trend[$i]['label'] }}</text>
                    @endforeach
                </svg>
                <p class="trend-note"><b>{{ $trendTotal }}</b> {{ $trendTotal === 1 ? 'ticket' : 'tickets' }} submitted in this period.</p>
            @else
                <div class="empty"><i class="fa-solid fa-chart-line"></i>No data for this period yet.</div>
            @endif
        </div>

        <div class="card">
            <div class="card-h"><h3>Service levels</h3><small>Last 30 days</small></div>
            <div class="sl-grid">
                <div class="sl">
                    <div class="k">Average first response</div>
                    <div class="v nums">{{ $fmt($service['first_response']) }}</div>
                    <small>{{ $service['answered'] }} of {{ $service['of'] }} {{ $service['of'] === 1 ? 'ticket' : 'tickets' }} answered</small>
                </div>
                <div class="sl">
                    <div class="k">Average resolution time</div>
                    <div class="v nums">{{ $fmt($service['resolution']) }}</div>
                    <small>From submission to work completed</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent activity + staff workload --}}
    <div class="{{ $workload !== null ? 'dash-half' : '' }}">
        <div class="card">
            <div class="card-h"><h3>Recent activity</h3></div>
            @if ($activity->isEmpty())
                <div class="empty"><i class="fa-regular fa-clock"></i>Nothing has happened yet.</div>
            @else
                <ul class="act">
                    @foreach ($activity as $log)
                        <li>
                            <span class="ic"><i class="fa-solid fa-bolt"></i></span>
                            <div class="tx">
                                @if ($log->request)<a href="{{ route('tickets.show', $log->request_id) }}">{{ $log->request->ticket_no }}</a> · @endif{{ $what($log) }}
                                <small>{{ $log->user?->name ?? 'System' }} · {{ $log->created_at?->diffForHumans() }}</small>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($workload !== null)
            <div class="card">
                <div class="card-h"><h3>Staff workload</h3><small>Open tickets per person</small></div>
                @if (count($workload) === 0)
                    <div class="empty"><i class="fa-solid fa-users"></i>No tickets are assigned yet.</div>
                @else
                    @php $maxOpen = max(1, max(array_column($workload, 'open'))); @endphp
                    <ul class="wl">
                        @foreach ($workload as $w)
                            <li>
                                <div class="top">
                                    <span>{{ $w['name'] }}</span>
                                    <span>{{ $w['open'] }} open @if ($w['overdue'] > 0) · <span class="bad">{{ $w['overdue'] }} overdue</span>@endif · {{ $w['completed'] }} done</span>
                                </div>
                                <div class="bar {{ $w['overdue'] > 0 ? 'hot' : '' }}"><span style="width:{{ round($w['open'] / $maxOpen * 100) }}%"></span></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    @push('scripts')
        @include('partials.live-refresh')
    @endpush
</x-app-layout>