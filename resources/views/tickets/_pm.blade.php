{{-- PM integration (Phase A + B): "Preventive Maintenance" card of a PM ticket: link back to the schedule, the checklist,
     the "Complete PM" form (In Progress, staff only) and the recorded result (after completion). --}}
@if ($ticket->isPmGenerated())
    @php
        $pmSchedule = $ticket->pmSchedule;
        $pmChecklist = $ticket->pmChecklist;
        $canOpenSchedule = (bool) auth()->user()?->can_do('manage_pm_schedules');
        $pmDone = $ticket->pm_completed_date !== null;
        $canComplete = $ticket->status === \App\Enums\TicketStatus::InProgress && (bool) auth()->user()?->can('work', $ticket);
        $pmStaff = $canComplete ? \App\Services\TicketActions::assignableStaff() : collect();
        $pmTech = $pmDone && $ticket->pm_technician_id ? \App\Models\User::find($ticket->pm_technician_id) : null;
        $pmErr = $errors->getBag('pmcomplete');
        $defaultTech = old('technician_id', $ticket->assigned_to ?? auth()->id());
    @endphp
    <section class="card" id="pm-info">
        <div class="card-h"><h3><i class="fa-solid fa-calendar-check"></i> Preventive Maintenance</h3></div>

        @if (session('pm_error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('pm_error') }}</div></div>
        @endif

        @if ($ticket->pm_escalated_at && $ticket->status->isPending())
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><div>PM is overdue and was escalated to the supervisor on {{ $ticket->pm_escalated_at->timezone(config('app.timezone'))->format('M j, Y') }}.</div></div>
        @elseif ($ticket->pm_overdue_at && $ticket->status->isPending())
            <div class="alert err" role="alert"><i class="fa-solid fa-bell"></i><div>PM is overdue since {{ $ticket->pm_due_date?->format('M j, Y') }}.</div></div>
        @endif

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
            @if ($pmDone)
                <div><div class="k">Completed on</div><div>{{ $ticket->pm_completed_date->format('M j, Y') }}</div></div>
                <div><div class="k">Technician</div><div>{{ $pmTech?->name ?? '—' }}</div></div>
                @if ($pmSchedule)
                    <div><div class="k">Next PM due</div><div>{{ $pmSchedule->next_due_date?->format('M j, Y') ?? 'Schedule ended' }}</div></div>
                @endif
            @endif
        </div>

        @if ($pmDone && $ticket->pm_notes)
            <div class="k" style="margin-top:14px">Completion notes</div>
            <div style="white-space:pre-line;word-break:break-word">{{ $ticket->pm_notes }}</div>
        @endif

        {{-- The "Complete PM" form: one checklist line = a tick box + an optional note --}}
        @if ($canComplete)
            <form method="POST" action="{{ route('tickets.pm-complete', $ticket) }}" id="pm-complete" style="margin-top:16px;border:1.5px solid #bfe6cc;background:var(--green-dim);border-radius:18px;padding:16px 18px">
                @csrf
                <div class="lbl" style="font-size:16px;color:#0f6a31"><i class="fa-solid fa-clipboard-check"></i> Complete this PM</div>

                <div class="field">
                    <label for="pm_completed_date" class="lbl">Date completed</label>
                    <input type="date" id="pm_completed_date" name="completed_date" required max="{{ now(config('app.timezone'))->toDateString() }}" value="{{ old('completed_date', now(config('app.timezone'))->toDateString()) }}">
                    @if ($pmErr->has('completed_date'))<p class="field-err">{{ $pmErr->first('completed_date') }}</p>@endif
                </div>

                <div class="field">
                    <label for="pm_technician_id" class="lbl">Technician</label>
                    <select id="pm_technician_id" name="technician_id" required>
                        @foreach ($pmStaff as $member)
                            <option value="{{ $member->id }}" @selected((int) $defaultTech === (int) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                    @if ($pmErr->has('technician_id'))<p class="field-err">{{ $pmErr->first('technician_id') }}</p>@endif
                </div>

                @if ($pmChecklist->isNotEmpty())
                    <div class="lbl">Checklist</div>
                    <p class="hint" style="margin:0 0 8px">Tick what was done. If a task was not done, write why.</p>
                    @foreach ($pmChecklist as $item)
                        <div style="padding:8px 0;border-top:1px solid var(--line)">
                            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:700;word-break:break-word">
                                <input type="checkbox" name="checklist[{{ $item->id }}][done]" value="1" @checked(old("checklist.{$item->id}.done", $item->is_done)) style="margin-top:4px">
                                <span>{{ $item->label }}</span>
                            </label>
                            <input type="text" name="checklist[{{ $item->id }}][note]" maxlength="500" placeholder="Note (required if not ticked)" value="{{ old("checklist.{$item->id}.note", $item->note) }}" style="margin-top:6px">
                            @if ($pmErr->has("checklist.{$item->id}.note"))<p class="field-err">{{ $pmErr->first("checklist.{$item->id}.note") }}</p>@endif
                        </div>
                    @endforeach
                @endif

                <div class="field" style="margin-top:12px">
                    <label for="pm_notes" class="lbl">Notes (optional)</label>
                    <textarea id="pm_notes" name="notes" rows="3" maxlength="2000" style="min-height:84px">{{ old('notes') }}</textarea>
                    @if ($pmErr->has('notes'))<p class="field-err">{{ $pmErr->first('notes') }}</p>@endif
                </div>

                <button class="btn btn-primary" style="background:var(--green);border-color:var(--green)"><i class="fa-solid fa-circle-check"></i> Mark PM completed</button>
            </form>
        @elseif (! $pmDone && in_array($ticket->status, [\App\Enums\TicketStatus::Approved, \App\Enums\TicketStatus::Assigned, \App\Enums\TicketStatus::Reopened], true) && auth()->user()?->can('work', $ticket))
            <p class="hint" style="margin:14px 0 0"><i class="fa-regular fa-circle-play"></i> Accept the task on the Maintenance board first. The completion form appears when the ticket is In Progress.</p>
        @endif

        {{-- The checklist (read only) once there is no form, with the results --}}
        @if ($pmChecklist->isNotEmpty() && ! $canComplete)
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