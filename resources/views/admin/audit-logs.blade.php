@php
    $label = fn (string $action) => str_replace('Faq', 'FAQ', \Illuminate\Support\Str::headline($action));
    $badge = fn (string $action) => match (true) {
        $action === 'login_failed' => 'ab-fail',
        in_array($action, ['login', 'logout', 'registered', 'password_reset'], true) => 'ab-auth',
        str_starts_with($action, 'user_') || $action === 'role_changed' => 'ab-user',
        str_starts_with($action, 'ticket_') => 'ab-ticket',
        str_starts_with($action, 'attachment_') => 'ab-file',
        $action === 'faq_deleted' => 'ab-fail',
        $action === 'faq_hidden' => 'ab-file',
        $action === 'faq_updated' => 'ab-ticket',
        $action === 'faq_reordered' => 'ab-auth',
        str_starts_with($action, 'faq_') => 'ab-ok',
        default => 'ab-ok',
    };
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Audit log</h2>
    </x-slot>

    <div class="content p6-wrap">

        <p class="p6-note">
            A read-only record of sign-ins, account changes, settings changes, FAQ changes and ticket activity.
            Ticket notes and reasons are not copied here; they stay on the ticket history.
        </p>

        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="f-user">User</label>
                    <select id="f-user" name="user">
                        <option value="">All users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected((string) ($filters['user'] ?? '') === (string) $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-action">Action</label>
                    <select id="f-action" name="action">
                        <option value="">All actions</option>
                        @foreach ($actions as $a)
                            <option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ $label($a) }}</option>
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
                    <input id="f-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Description, user name or email">
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="{{ route('admin.audit-logs.index') }}" class="btn">Clear</a>
            </div>
        </form>

        <div class="card" style="padding:18px">
            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td data-label="When" style="white-space:nowrap">{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                                <td data-label="User" class="p6-who">
                                    @if ($log->user)
                                        <div><b>{{ $log->user->name }}</b><small>{{ $log->user->email }}</small></div>
                                    @else
                                        <span class="sub">{{ $log->action === 'login_failed' ? 'Unknown' : 'System' }}</span>
                                    @endif
                                </td>
                                <td data-label="Action">
                                    <span class="badge nocap {{ $badge($log->action) }}">{{ $label($log->action) }}</span>
                                </td>
                                <td data-label="Description" class="p6-desc">
                                    @if (isset($ticketLinks[$log->id]))
                                        <a href="{{ $ticketLinks[$log->id] }}">{{ $log->description }}</a>
                                    @else
                                        {{ $log->description }}
                                    @endif
                                </td>
                                <td data-label="IP" class="mono">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty"><i class="fa-regular fa-folder-open"></i>No audit entries match these filters.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $logs->links() }}
    </div>
</x-app-layout>
