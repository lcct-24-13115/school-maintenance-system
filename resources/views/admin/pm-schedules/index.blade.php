@php
    $hasFilters = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">Preventive maintenance</h2>
    </x-slot>

    <div class="content p6-wrap">
        <x-help-link topic="Preventive maintenance" row />

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <p class="p6-note">
            A PM schedule repeats on its own: for example <b>Aircon Cleaning</b> every 3 months or <b>Generator Checkup</b> every month.
            A ticket is made automatically every day at 1:00 AM, a few days before each due date. See every due date on the <a href="{{ route('maintenance-calendar.index') }}"><b>PM calendar</b></a>.
        </p>

        @php $canManage = (bool) auth()->user()?->can_do('manage_pm_schedules'); @endphp

        @unless ($canManage)
            <p class="p6-note"><i class="fa-solid fa-eye"></i> You can look at the PM schedules but not change them.</p>
        @endunless

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            @if ($canManage)
                <a href="{{ route('admin.pm-schedules.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New schedule</a>
            @endif
            <a href="{{ route('admin.equipment.index') }}" class="btn"><i class="fa-solid fa-screwdriver-wrench"></i> Equipment</a>
        </div>

        <form method="GET" action="{{ route('admin.pm-schedules.index') }}" class="card">
            <div class="filters" style="margin-bottom:0">
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search title" aria-label="Search title">

                <select name="facility_id" aria-label="Facility">
                    <option value="">All facilities</option>
                    @foreach ($facilities as $f)
                        <option value="{{ $f->id }}" @selected((string) $filters['facility_id'] === (string) $f->id)>{{ $f->name }}</option>
                    @endforeach
                </select>

                <select name="equipment_id" aria-label="Equipment">
                    <option value="">All equipment</option>
                    @foreach ($equipmentList as $e)
                        <option value="{{ $e->id }}" @selected((string) $filters['equipment_id'] === (string) $e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>

                <select name="frequency_type" aria-label="Frequency">
                    <option value="">Any frequency</option>
                    @foreach ($frequencies as $value => $label)
                        <option value="{{ $value }}" @selected($filters['frequency_type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="active" aria-label="Status">
                    <option value="">Active and inactive</option>
                    <option value="1" @selected($filters['active'] === '1')>Active only</option>
                    <option value="0" @selected($filters['active'] === '0')>Inactive only</option>
                </select>

                <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                @if ($hasFilters)<a href="{{ route('admin.pm-schedules.index') }}" class="btn">Clear</a>@endif
            </div>
        </form>

        <div class="card" style="padding:0">
            @if ($schedules->isEmpty())
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-calendar-check"></i>No PM schedules found.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                @if ($canManage)
                                    <th style="width:34px"><input type="checkbox" aria-label="Select all" onclick="document.querySelectorAll('.bulk-cb').forEach(function (c) { c.checked = this.checked }, this)"></th>
                                @endif
                                <th>Schedule</th>
                                <th>Where</th>
                                <th>Repeats</th>
                                <th>Next due</th>
                                <th>Assigned to</th>
                                <th style="width:110px">Status</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedules as $s)
                                @php $confirm = "Delete the PM schedule \"{$s->title}\"? This cannot be undone."; @endphp
                                <tr @if (! $s->is_active) style="opacity:.62" @endif>
                                    @if ($canManage)
                                        <td data-label="Select"><input type="checkbox" class="bulk-cb" name="ids[]" value="{{ $s->id }}" form="bulk-form" aria-label="Select {{ $s->title }}"></td>
                                    @endif
                                    <td data-label="Schedule" style="word-break:break-word">
                                        <b>{{ $s->title }}</b>
                                        @if ($s->ticket_priority === 'emergency')<span class="badge nocap ab-fail" style="margin-left:6px">Emergency</span>@endif
                                    </td>
                                    <td data-label="Where">
                                        {{ $s->facility?->name }}
                                        @if ($s->equipment)<div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $s->equipment->name }}</div>@endif
                                    </td>
                                    <td data-label="Repeats">
                                        {{ $s->frequencyText() }}
                                        <div class="sub" style="font-size:12.5px;color:var(--text-3)">Ticket {{ $s->lead_time_days }} {{ \Illuminate\Support\Str::plural('day', $s->lead_time_days) }} before</div>
                                    </td>
                                    <td data-label="Next due">{{ $s->next_due_date ? $s->next_due_date->format('M j, Y') : 'Ended' }}</td>
                                    <td data-label="Assigned to">{{ $s->assignee?->name ?? '—' }}</td>
                                    <td data-label="Status">
                                        @if ($s->is_active)
                                            <span class="badge nocap ab-ok">Active</span>
                                        @else
                                            <span class="badge nocap ab-fail">Inactive</span>
                                        @endif
                                    </td>
                                    <td data-label="Actions" style="text-align:right;white-space:nowrap">
                                        @if ($canManage)
                                        <form method="POST" action="{{ route('admin.pm-schedules.generate', $s) }}" style="display:inline"
                                              onsubmit="return confirm('Make the ticket for the next due date now? The schedule then moves to the following date.')">
                                            @csrf
                                            <button class="btn btn-sm" title="Make the ticket for the next due date now" @disabled(! $s->is_active || ! $s->next_due_date)><i class="fa-solid fa-ticket"></i> Ticket now</button>
                                        </form>
                                        @endif
                                        <a class="btn btn-sm" href="{{ route('admin.pm-schedules.history', $s) }}" title="Who changed this schedule"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
                                        @if ($canManage)
                                        <form method="POST" action="{{ route('admin.pm-schedules.duplicate', $s) }}" style="display:inline">
                                            @csrf
                                            <button class="btn btn-sm" title="Duplicate" aria-label="Duplicate"><i class="fa-regular fa-copy"></i></button>
                                        </form>
                                        <a class="btn btn-sm" href="{{ route('admin.pm-schedules.edit', $s) }}"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <form method="POST" action="{{ route('admin.pm-schedules.toggle', $s) }}" style="display:inline">
                                            @csrf
                                            @method('PATCH')
                                            <button class="btn btn-sm">
                                                @if ($s->is_active)<i class="fa-solid fa-pause"></i> Deactivate @else<i class="fa-solid fa-play"></i> Activate @endif
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.pm-schedules.destroy', $s) }}" style="display:inline"
                                              onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }})">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($canManage)
        <form id="bulk-form" method="POST" action="{{ route('admin.pm-schedules.bulk') }}" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf
            <span style="font-size:13.5px;color:var(--text-2)">Ticked schedules:</span>
            <button type="submit" name="action" value="pause" class="btn btn-sm"><i class="fa-solid fa-pause"></i> Pause</button>
            <button type="submit" name="action" value="resume" class="btn btn-sm"><i class="fa-solid fa-play"></i> Resume</button>
            @error('ids')<span style="color:var(--red);font-weight:700;font-size:13px">{{ $message }}</span>@enderror
        </form>
        @endif

        @include('admin.pm-schedules._runs')

        @include('partials.pager', ['paginator' => $schedules])
    </div>
</x-app-layout>