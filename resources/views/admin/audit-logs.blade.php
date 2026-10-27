<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit log</h2>
    </x-slot>

    @php
        $badge = fn (string $action) => match (true) {
            $action === 'login_failed' => 'bg-red-100 text-red-800',
            in_array($action, ['login', 'logout', 'registered', 'password_reset'], true) => 'bg-gray-100 text-gray-800',
            str_starts_with($action, 'user_') || $action === 'role_changed' => 'bg-purple-100 text-purple-800',
            str_starts_with($action, 'ticket_') => 'bg-blue-100 text-blue-800',
            str_starts_with($action, 'attachment_') => 'bg-amber-100 text-amber-800',
            default => 'bg-green-100 text-green-800',
        };
    @endphp

    <div class="py-4">
        <div class="max-w-7xl mx-auto space-y-4 px-3 sm:px-6 lg:px-8">

            <p class="text-sm text-gray-600">
                A read-only record of sign-ins, account changes, settings changes and ticket activity.
                Ticket notes and reasons are not copied here; they stay on the ticket history.
            </p>

            <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="grid grid-cols-1 gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
                <label class="block text-sm font-semibold text-gray-700">User
                    <select name="user" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                        <option value="">All users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected((string) ($filters['user'] ?? '') === (string) $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-semibold text-gray-700">Action
                    <select name="action" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                        <option value="">All actions</option>
                        @foreach ($actions as $a)
                            <option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ \Illuminate\Support\Str::headline($a) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-semibold text-gray-700">From
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('from')" class="mt-1" />
                </label>

                <label class="block text-sm font-semibold text-gray-700">To
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('to')" class="mt-1" />
                </label>

                <label class="block text-sm font-semibold text-gray-700 lg:col-span-2">Search
                    <input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Description, user name or e-mail"
                           class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                </label>

                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
                    <button class="min-h-[44px] rounded-lg bg-blue-600 px-5 font-bold text-white hover:bg-blue-700">Filter</button>
                    <a href="{{ route('admin.audit-logs.index') }}" class="inline-flex min-h-[44px] items-center rounded-lg px-4 font-semibold text-gray-700 hover:bg-gray-100">Clear</a>
                </div>
            </form>

            <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3 text-gray-900">
                                    @if ($log->user)
                                        <div class="font-semibold">{{ $log->user->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $log->user->email }}</div>
                                    @else
                                        <span class="text-gray-500">{{ $log->action === 'login_failed' ? 'Unknown' : 'System' }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $badge($log->action) }}">{{ \Illuminate\Support\Str::headline($log->action) }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-800">
                                    @if (isset($ticketLinks[$log->id]))
                                        <a href="{{ $ticketLinks[$log->id] }}" class="font-semibold text-blue-700 hover:underline">{{ $log->description }}</a>
                                    @else
                                        {{ $log->description }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">No audit entries match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $logs->links() }}
        </div>
    </div>
</x-app-layout>
