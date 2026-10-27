@php
    $fmt = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
    // open the filter panel when any filter besides the search box is active
    $otherFilters = collect($filters)->except('q')->filter()->isNotEmpty();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tickets</h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">

            {{-- Search + filters (GET, so every result page can be bookmarked and pagination keeps them) --}}
            <form method="GET" action="{{ route('tickets.index') }}" class="mb-4 rounded-xl bg-white p-4 shadow-sm"
                  x-data="{ open: {{ $otherFilters ? 'true' : 'false' }} }">
                <div class="flex gap-2">
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search ticket no, description, location, reason, requester"
                           class="min-h-[44px] w-full rounded-lg border-gray-300 text-base" aria-label="Search tickets">
                    <button class="min-h-[44px] rounded-lg bg-blue-600 px-4 font-bold text-white hover:bg-blue-700">Search</button>
                </div>

                <button type="button" @click="open = ! open" class="mt-3 min-h-[44px] text-sm font-semibold text-blue-700">
                    <span x-text="open ? 'Hide filters ▲' : 'Show filters ▼'"></span>
                </button>

                <div x-show="open" style="display:none" class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block text-sm font-semibold text-gray-700">From (submitted)
                        <input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    </label>
                    <label class="block text-sm font-semibold text-gray-700">To (submitted)
                        <input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    </label>
                    @if (count($filters['status']) > 1)
                        {{-- a dashboard card can link with several statuses at once; keep them when re-applying --}}
                        <div class="text-sm font-semibold text-gray-700">Status
                            <div class="mt-1 flex min-h-[44px] items-center rounded-lg bg-gray-50 px-3 text-gray-700">
                                {{ collect($filters['status'])->map(fn ($v) => $statusOptions[$v])->implode(', ') }}
                            </div>
                            @foreach ($filters['status'] as $v)<input type="hidden" name="status[]" value="{{ $v }}">@endforeach
                        </div>
                    @else
                        <label class="block text-sm font-semibold text-gray-700">Status
                            <select name="status[]" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                                <option value="">All statuses</option>
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(in_array($value, $filters['status'], true))>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="block text-sm font-semibold text-gray-700">Category
                        <select name="category_id" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                            <option value="">All categories</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected((string) $filters['category_id'] === (string) $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-gray-700">Assigned to
                        <select name="assigned_to" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                            <option value="">Anyone</option>
                            <option value="unassigned" @selected($filters['assigned_to'] === 'unassigned')>Unassigned</option>
                            @foreach ($assignees as $a)
                                <option value="{{ $a->id }}" @selected((string) $filters['assigned_to'] === (string) $a->id)>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-gray-700">Department
                        <select name="department_id" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                            <option value="">All departments</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected((string) $filters['department_id'] === (string) $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm font-semibold text-gray-700">
                        <input type="checkbox" name="emergency" value="1" @checked($filters['emergency']) class="h-5 w-5 rounded border-gray-300">
                        Emergency only
                    </label>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm font-semibold text-gray-700">
                        <input type="checkbox" name="overdue" value="1" @checked($filters['overdue']) class="h-5 w-5 rounded border-gray-300">
                        Overdue only
                    </label>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm font-semibold text-gray-700">
                        <input type="checkbox" name="mine" value="1" @checked($filters['mine']) class="h-5 w-5 rounded border-gray-300">
                        Only tickets I filed
                    </label>
                </div>

                <div class="mt-3 flex items-center gap-4">
                    <button class="min-h-[44px] rounded-lg bg-gray-800 px-5 font-bold text-white hover:bg-gray-700">Apply</button>
                    @if ($hasFilters)
                        <a href="{{ route('tickets.index') }}" class="flex min-h-[44px] items-center text-sm font-semibold text-red-700 hover:underline">Clear filters</a>
                    @endif
                </div>

                @if ($errors->any())
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first() }}</p>
                @endif
            </form>

            @if ($tickets->isEmpty())
                <div class="rounded-xl bg-white p-8 text-center shadow-sm">
                    <p class="text-lg font-semibold text-gray-700">No tickets found.</p>
                    <p class="mt-1 text-gray-500">{{ $hasFilters ? 'Try a different search or clear the filters.' : 'There are no tickets you can see yet.' }}</p>
                    @if ($hasFilters)
                        <a href="{{ route('tickets.index') }}" class="mt-3 inline-flex min-h-[44px] items-center font-semibold text-blue-700 hover:underline">Clear filters</a>
                    @endif
                </div>
            @else
                <p class="mb-2 text-sm text-gray-500">{{ $tickets->total() }} ticket(s) · emergencies first, then oldest first</p>

                {{-- Phones: cards --}}
                <div class="space-y-3 md:hidden">
                    @foreach ($tickets as $t)
                        <a href="{{ route('tickets.show', $t) }}" class="block rounded-xl bg-white p-4 shadow-sm {{ $t->is_emergency && ! $t->status->isTerminal() ? 'ring-2 ring-red-500' : '' }}">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-extrabold text-gray-900">{{ $t->ticket_no }}</span>
                                <x-status-badge :status="$t->status" />
                                <x-ticket-badges :ticket="$t" />
                            </div>
                            <div class="mt-1 break-words text-lg font-bold text-gray-900">📍 {{ $t->location }}</div>
                            <div class="break-words text-sm text-gray-600">{{ $t->category?->name }}</div>
                            <div class="mt-1 text-sm text-gray-700">👤 {{ $t->requester->name }} · {{ $t->department?->name }}</div>
                            @if ($t->assignee)
                                <div class="text-sm font-semibold text-amber-700">Assigned to {{ $t->assignee->name }}</div>
                            @endif
                            <div class="mt-1 text-xs text-gray-500">Submitted {{ $fmt($t->submitted_at) }}</div>
                            @if ($t->completed_at)
                                <div class="text-xs text-gray-500">Completed {{ $fmt($t->completed_at) }}</div>
                            @endif
                        </a>
                    @endforeach
                </div>

                {{-- Desktop: table --}}
                <div class="hidden overflow-x-auto rounded-xl bg-white shadow-sm md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-bold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-3">Ticket</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Location</th>
                                <th class="px-3 py-3">Category</th>
                                <th class="px-3 py-3">Requester</th>
                                <th class="px-3 py-3">Assigned to</th>
                                <th class="px-3 py-3">Submitted</th>
                                <th class="px-3 py-3">Completed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($tickets as $t)
                                <tr class="{{ $t->is_emergency && ! $t->status->isTerminal() ? 'bg-red-50' : '' }}">
                                    <td class="px-3 py-3 font-bold"><a href="{{ route('tickets.show', $t) }}" class="text-blue-700 hover:underline">{{ $t->ticket_no }}</a></td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-1"><x-status-badge :status="$t->status" /><x-ticket-badges :ticket="$t" /></div>
                                    </td>
                                    <td class="px-3 py-3">{{ $t->location }}</td>
                                    <td class="px-3 py-3">{{ $t->category?->name }}</td>
                                    <td class="px-3 py-3">{{ $t->requester->name }}<div class="text-xs text-gray-500">{{ $t->department?->name }}</div></td>
                                    <td class="px-3 py-3">{{ $t->assignee?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $fmt($t->submitted_at) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $fmt($t->completed_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $tickets->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
