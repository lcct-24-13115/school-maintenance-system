@php
    $typeLabel = fn (?string $t) => $t ? \Illuminate\Support\Str::headline($t) : 'Other';
    $statusClass = fn ($log) => 'el-'.$log->effectiveStatus();
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/emaillog.css') }}?v={{ @filemtime(public_path('css/emaillog.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">E-mail log</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        @if ($fakeMailer)
            <div class="alert warn" role="status">
                <i class="fa-solid fa-circle-info"></i>
                <span>
                    MAIL_MAILER is <b>{{ $mailer }}</b>, so e-mails are only written to the log file and are <b>not really sent</b>.
                    "Delivered" below means "written to the log". Set MAIL_MAILER=smtp in .env to send real e-mails.
                </span>
            </div>
        @endif

        <p class="p6-note">
            Every e-mail the system sends (ticket updates, password reset, test) with its real result.
            A failed ticket e-mail can be sent again. Password-reset e-mails are listed without their content.
        </p>

        <div class="el-stats">
            <a class="el-stat" href="{{ route('admin.email-log.index') }}"><b>{{ $counts['all'] }}</b><span>All e-mails</span></a>
            <a class="el-stat el-stat-ok" href="{{ route('admin.email-log.index', ['status' => 'delivered']) }}"><b>{{ $counts['delivered'] }}</b><span>Delivered</span></a>
            <a class="el-stat el-stat-bad" href="{{ route('admin.email-log.index', ['status' => 'failed']) }}"><b>{{ $counts['failed'] }}</b><span>Failed</span></a>
            <a class="el-stat el-stat-warn" href="{{ route('admin.email-log.index', ['status' => 'unconfirmed']) }}"><b>{{ $counts['unconfirmed'] }}</b><span>Not confirmed</span></a>
        </div>

        <form method="GET" action="{{ route('admin.email-log.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="f-status">Status</label>
                    <select id="f-status" name="status">
                        <option value="">All</option>
                        @foreach (['delivered' => 'Delivered', 'failed' => 'Failed', 'sending' => 'Sending', 'unconfirmed' => 'Not confirmed'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-type">Kind</label>
                    <select id="f-type" name="type">
                        <option value="">All kinds</option>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ $typeLabel($t) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-from">From</label>
                    <input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
                    @error('from')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="f-to">To</label>
                    <input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
                    @error('to')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field wide">
                    <label for="f-q">Search</label>
                    <input id="f-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Recipient, subject or ticket number">
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="{{ route('admin.email-log.index') }}" class="btn">Clear</a>
            </div>
        </form>

        <div class="card" style="padding:18px">
            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>Kind</th>
                            <th>Status</th>
                            <th>Tries</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td data-label="When" style="white-space:nowrap">{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                                <td data-label="To" class="p6-who">
                                    <div>
                                        <b>{{ $log->to_name ?: $log->to_email }}</b>
                                        @if ($log->to_name)<small>{{ $log->to_email }}</small>@endif
                                    </div>
                                </td>
                                <td data-label="Subject" class="p6-desc">
                                    <a href="{{ route('admin.email-log.show', $log->id) }}">{{ $log->subject }}</a>
                                    @if ($log->status === 'failed' && $log->error)
                                        <div class="el-err">{{ \Illuminate\Support\Str::limit($log->error, 120) }}</div>
                                    @endif
                                </td>
                                <td data-label="Kind"><span class="badge nocap ab-auth">{{ $typeLabel($log->type) }}</span></td>
                                <td data-label="Status"><span class="badge nocap {{ $statusClass($log) }}">{{ $log->statusLabel() }}</span></td>
                                <td data-label="Tries">{{ $log->attempts }}</td>
                                <td data-label="">
                                    @if ($log->canRetry())
                                        <form method="POST" action="{{ route('admin.email-log.retry', $log->id) }}">
                                            @csrf
                                            <button type="submit" class="btn"><i class="fa-solid fa-rotate-right"></i> Send again</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty"><i class="fa-regular fa-envelope"></i>No e-mails match these filters.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $logs->links() }}
    </div>
</x-app-layout>
