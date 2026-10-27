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
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reports</h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto space-y-4 px-3 sm:px-6 lg:px-8">

            <form method="GET" action="{{ route('reports.index') }}" class="grid grid-cols-1 gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
                <label class="block text-sm font-semibold text-gray-700">From (submitted)
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('from')" class="mt-1" />
                </label>
                <label class="block text-sm font-semibold text-gray-700">To (submitted)
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('to')" class="mt-1" />
                </label>
                <div class="flex items-end gap-2 lg:col-span-2">
                    <button class="min-h-[44px] rounded-lg bg-blue-600 px-5 font-bold text-white hover:bg-blue-700">Apply</button>
                    <a href="{{ route('reports.index') }}" class="inline-flex min-h-[44px] items-center rounded-lg px-4 font-semibold text-gray-700 hover:bg-gray-100">Clear</a>
                    @if ($canExport)
                        <a href="{{ route('reports.export', $query) }}" class="ms-auto inline-flex min-h-[44px] items-center rounded-lg border border-green-600 px-4 font-bold text-green-700 hover:bg-green-50">
                            Download CSV
                        </a>
                    @endif
                </div>
            </form>

            <p class="text-sm text-gray-600">
                Showing tickets submitted <strong>{{ $range }}</strong>. Only tickets you are allowed to see are counted.
            </p>

            {{-- headline numbers --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-gray-500">Tickets</div>
                    <div class="mt-1 text-3xl font-bold text-gray-900">{{ $metrics['total'] }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-gray-500">Emergency</div>
                    <div class="mt-1 text-3xl font-bold text-red-700">{{ $metrics['emergency'] }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-gray-500">Overdue now</div>
                    <div class="mt-1 text-3xl font-bold text-amber-700">{{ $metrics['overdue'] }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-gray-500">Avg. time to approve</div>
                    <div class="mt-1 text-3xl font-bold text-gray-900">{{ $hours($metrics['avg_approval_hours']) }}</div>
                </div>
                <div class="col-span-2 rounded-xl bg-white p-4 shadow-sm lg:col-span-1">
                    <div class="text-xs font-semibold uppercase text-gray-500">Avg. time to complete</div>
                    <div class="mt-1 text-3xl font-bold text-gray-900">{{ $hours($metrics['avg_completion_hours']) }}</div>
                </div>
            </div>

            {{-- charts: Chart.js comes from the Vite bundle (resources/js/reports.js), never from a CDN --}}
            <div id="report-charts" data-charts="{{ json_encode($charts) }}" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ([
                    'status' => 'Tickets per status',
                    'category' => 'Tickets per category',
                    'monthly' => 'Tickets per month',
                    'department' => 'Tickets per department',
                ] as $key => $title)
                    <section data-chart-card class="rounded-xl bg-white p-4 shadow-sm">
                        <h3 class="mb-2 font-bold text-gray-900">{{ $title }}</h3>
                        <div class="relative h-64">
                            <canvas id="chart-{{ $key }}" role="img" aria-label="{{ $title }}"></canvas>
                        </div>
                        <p data-empty class="hidden py-10 text-center text-sm text-gray-500">No tickets in this range.</p>
                    </section>
                @endforeach
            </div>

            <section class="rounded-xl bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-bold text-gray-900">Workload per assigned staff</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="py-2 pe-4">Staff</th>
                                <th class="py-2 pe-4 text-end">Assigned</th>
                                <th class="py-2 pe-4 text-end">Still open</th>
                                <th class="py-2 text-end">Completed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($metrics['workload'] as $row)
                                <tr>
                                    <td class="py-2 pe-4 font-semibold text-gray-900">{{ $row['name'] }}</td>
                                    <td class="py-2 pe-4 text-end">{{ $row['assigned'] }}</td>
                                    <td class="py-2 pe-4 text-end">{{ $row['open'] }}</td>
                                    <td class="py-2 text-end">{{ $row['completed'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-gray-500">No assigned tickets in this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
