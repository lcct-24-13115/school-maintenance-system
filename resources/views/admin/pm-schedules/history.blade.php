<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">Schedule history</h2>
    </x-slot>

    <div class="content p6-wrap">

        <div style="margin-bottom:14px">
            <a href="{{ route('admin.pm-schedules.index') }}" class="btn"><i class="fa-solid fa-arrow-left"></i> PM schedules</a>
        </div>

        <div class="card">
            <h3 class="p6-card-t" style="margin-bottom:4px;word-break:break-word">{{ $schedule->title }}</h3>
            <div style="font-size:13.5px;color:var(--text-2)">
                {{ $schedule->facility?->name }}
                @if ($schedule->equipment) &middot; {{ $schedule->equipment->name }} @endif
                &middot; {{ $schedule->frequencyText() }}
                &middot; {{ $schedule->is_active ? 'Active' : 'Inactive' }}
            </div>
            <div style="font-size:13px;color:var(--text-3);margin-top:6px">
                Made {{ $schedule->created_at?->format('M j, Y g:i A') }}@if ($schedule->creator) by {{ $schedule->creator->name }}@endif
            </div>
        </div>

        <div class="card" style="padding:0">
            @if ($entries->isEmpty())
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-clock-rotate-left"></i>No changes recorded yet.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:190px">When</th>
                                <th style="width:200px">Who</th>
                                <th>What happened</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $e)
                                <tr>
                                    <td data-label="When" style="white-space:nowrap">{{ $e->created_at?->format('M j, Y g:i A') }}</td>
                                    <td data-label="Who">{{ $e->user?->name ?? 'System' }}</td>
                                    <td data-label="What happened" style="word-break:break-word">{{ $e->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @include('partials.pager', ['paginator' => $entries])
    </div>
</x-app-layout>