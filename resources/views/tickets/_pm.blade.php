{{-- PM integration (Phase A): "Preventive Maintenance" card of a PM ticket: link back to the schedule + the checklist copy. --}}
@if ($ticket->isPmGenerated())
    @php
        $pmSchedule = $ticket->pmSchedule;
        $pmChecklist = $ticket->pmChecklist;
        $canOpenSchedule = (bool) auth()->user()?->can_do('manage_pm_schedules');
    @endphp
    <section class="card" id="pm-info">
        <div class="card-h"><h3><i class="fa-solid fa-calendar-check"></i> Preventive Maintenance</h3></div>
        <div class="kv">
            <div>
                <div class="k">Schedule</div>
                <div>
                    @if ($pmSchedule && $canOpenSchedule)
                        <a href="{{ route('admin.pm-schedules.edit', $pmSchedule) }}">{{ $pmSchedule->title }} <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.75em"></i></a>
                    @elseif ($pmSchedule)
                        {{ $pmSchedule->title }}
                    @else
                        <span class="hint">The schedule was deleted.</span>
                    @endif
                </div>
            </div>
            <div><div class="k">Repeats</div><div>{{ $pmSchedule?->frequencyText() ?? '—' }}</div></div>
            <div><div class="k">PM due date</div><div>{{ $ticket->pm_due_date?->format('M j, Y') ?? '—' }}</div></div>
            <div>
                <div class="k">Next due date based on</div>
                <div>{{ $pmSchedule ? ($pmSchedule->isFloating() ? 'Actual completion day (floating)' : 'Original schedule (fixed)') : '—' }}</div>
            </div>
        </div>

        @if ($pmChecklist->isNotEmpty())
            <div class="k" style="margin-top:14px">Checklist</div>
            <ul style="list-style:none;margin:6px 0 0;padding:0">
                @foreach ($pmChecklist as $item)
                    <li style="display:flex;gap:8px;align-items:flex-start;padding:4px 0;word-break:break-word">
                        <i class="{{ $item->is_done ? 'fa-solid fa-square-check' : 'fa-regular fa-square' }}" style="margin-top:3px;{{ $item->is_done ? 'color:#16a34a' : '' }}"></i>
                        <span>{{ $item->label }}@if ($item->note)<div class="sub">{{ $item->note }}</div>@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif