@use('App\Services\TicketActions', 'A')

@php
    $has = fn (string $action) => in_array($action, $actions, true);
    // one coloured box per action: border colour, background
    $box = fn (string $border, string $bg = '#fff') => "border:1.5px solid {$border};background:{$bg};border-radius:18px;padding:16px 18px;margin-bottom:14px";
    $area = 'min-height:84px';
@endphp

{{-- Action panel (Phase 4A logic, Phase 4 design). Each action shows ONLY when the status allows it AND the user has the capability;
     the controllers check both again. Errors go to one bag per form (approve, disapprove, assign, flag, clear, note). --}}
<section class="card" id="actions">
    <div class="card-h"><h3>Actions</h3></div>

    @if (session('error'))
        <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('error') }}</div></div>
    @endif

    @if ($actions === [])
        <p class="hint" style="margin:0">
            @if ($ticket->status->isTerminal())
                This ticket is {{ $ticket->status->label() }}. No further actions are possible.
                @if ($ticket->status === \App\Enums\TicketStatus::Closed && (int) $ticket->requester_id === (int) auth()->id())
                    The reopen window has ended; file a new ticket if the problem is back.
                @endif
            @else
                There is nothing for you to do on this ticket right now.
            @endif
        </p>
    @endif

    <div>

        {{-- Admin: Submitted -> Pending Authorization --}}
        @if ($has(A::SEND_FOR_AUTHORIZATION))
            <form method="POST" action="{{ route('tickets.send-for-authorization', $ticket) }}" style="{{ $box('#f1d78f', 'var(--amber-dim)') }}">
                @csrf
                <div class="hint" style="margin:0 0 10px;color:var(--text-2)">Put this ticket in the authorizer's queue.</div>
                <button class="btn btn-primary" style="background:var(--gold);border-color:var(--gold);color:var(--navy)"><i class="fa-solid fa-paper-plane"></i> Send for authorization</button>
            </form>
        @endif

        {{-- Authorizer: Approve / Disapprove --}}
        @if ($has(A::APPROVE))
            <form method="POST" action="{{ route('tickets.approve', $ticket) }}" enctype="multipart/form-data" style="{{ $box('#bfe6cc', 'var(--green-dim)') }}">
                @csrf
                <div class="lbl" style="font-size:16px;color:#0f6a31"><i class="fa-solid fa-circle-check"></i> Approve</div>
                <div class="field">
                    <label for="remarks" class="lbl">Remarks (optional)</label>
                    <textarea id="remarks" name="remarks" rows="2" style="{{ $area }}">{{ old('remarks') }}</textarea>
                    @if ($errors->approve->has('remarks'))<p class="field-err">{{ $errors->approve->first('remarks') }}</p>@endif
                </div>
                <div class="field">
                    <div class="lbl">Signed file (optional)</div>
                    <x-file-picker name="files" />
                    @foreach ($errors->approve->get('files') as $message)<p class="field-err">{{ $message }}</p>@endforeach
                    @foreach ($errors->approve->get('files.*') as $messages)
                        @foreach ((array) $messages as $message)<p class="field-err">{{ $message }}</p>@endforeach
                    @endforeach
                </div>
                <p class="hint" style="margin:0 0 12px">The app records who authorized and when. It does not replace the official signatures: keep the signed paper or scan on file.</p>
                <button class="btn btn-primary" style="background:var(--green);border-color:var(--green)">Approve</button>
            </form>
        @endif

        @if ($has(A::DISAPPROVE))
            <form method="POST" action="{{ route('tickets.disapprove', $ticket) }}" style="{{ $box('#f3c9c5', 'var(--red-dim)') }}">
                @csrf
                <div class="lbl" style="font-size:16px;color:#9d2820"><i class="fa-solid fa-circle-xmark"></i> Disapprove</div>
                <div class="field">
                    <label for="reason" class="lbl">Reason (required)</label>
                    <textarea id="reason" name="reason" rows="2" required style="{{ $area }}">{{ old('reason') }}</textarea>
                    @if ($errors->disapprove->has('reason'))<p class="field-err">{{ $errors->disapprove->first('reason') }}</p>@endif
                </div>
                <button class="btn btn-danger">Disapprove</button>
            </form>
        @endif

        {{-- Admin: Assign / Reassign --}}
        @if ($has(A::ASSIGN) || $has(A::REASSIGN))
            @php $reassign = $has(A::REASSIGN); @endphp
            <form method="POST" action="{{ route('tickets.assign', $ticket) }}" style="{{ $box('#bcd5ee', 'var(--sky)') }}">
                @csrf
                <div class="lbl" style="font-size:16px"><i class="fa-solid fa-user-gear"></i> {{ $reassign ? 'Reassign' : 'Assign' }}</div>
                @if ($reassign)
                    <div class="hint" style="margin:0 0 10px;color:var(--text-2)">Currently assigned to <strong>{{ $ticket->assignee?->name }}</strong>.</div>
                @endif
                <div class="field">
                    <label for="assignee_id" class="lbl">Maintenance staff</label>
                    <select id="assignee_id" name="assignee_id" required>
                        <option value="">Choose staff…</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((int) old('assignee_id') === $member->id)>
                                {{ $member->name }} — {{ $member->open_tickets_count }} open {{ \Illuminate\Support\Str::plural('ticket', $member->open_tickets_count) }}
                            </option>
                        @endforeach
                    </select>
                    @if ($errors->assign->has('assignee_id'))<p class="field-err">{{ $errors->assign->first('assignee_id') }}</p>@endif
                </div>
                <div class="field">
                    <label for="assign_note" class="lbl">Note {{ $reassign ? '(required: why is it being reassigned?)' : '(optional)' }}</label>
                    <textarea id="assign_note" name="assign_note" rows="2" @required($reassign) style="{{ $area }}">{{ old('assign_note') }}</textarea>
                    @if ($errors->assign->has('assign_note'))<p class="field-err">{{ $errors->assign->first('assign_note') }}</p>@endif
                </div>
                <button class="btn btn-primary">{{ $reassign ? 'Reassign' : 'Assign' }}</button>
            </form>
        @endif

        {{-- Emergency flag --}}
        @if ($has(A::FLAG_EMERGENCY))
            <form method="POST" action="{{ route('tickets.emergency.flag', $ticket) }}" style="{{ $box('#f3c9c5') }}">
                @csrf
                <div class="lbl" style="font-size:16px;color:var(--red)"><i class="fa-solid fa-triangle-exclamation"></i> Flag emergency</div>
                <div class="field">
                    <label for="emergency_reason" class="lbl">Reason (required)</label>
                    <textarea id="emergency_reason" name="emergency_reason" rows="2" required style="{{ $area }}">{{ old('emergency_reason') }}</textarea>
                    @if ($errors->flag->has('emergency_reason'))<p class="field-err">{{ $errors->flag->first('emergency_reason') }}</p>@endif
                </div>
                <button class="btn btn-danger">Flag emergency</button>
            </form>
        @endif

        @if ($has(A::CLEAR_EMERGENCY))
            <form method="POST" action="{{ route('tickets.emergency.clear', $ticket) }}" style="{{ $box('var(--line)') }}">
                @csrf
                <div class="lbl" style="font-size:16px">Clear emergency</div>
                <div class="field">
                    <label for="clear_note" class="lbl">Note (optional)</label>
                    <textarea id="clear_note" name="clear_note" rows="2" style="{{ $area }}">{{ old('clear_note') }}</textarea>
                    @if ($errors->clear->has('clear_note'))<p class="field-err">{{ $errors->clear->first('clear_note') }}</p>@endif
                </div>
                <p class="hint" style="margin:0 0 12px">The ticket goes back to its original place in the queue (by the time it was submitted).</p>
                <button class="btn">Clear emergency</button>
            </form>
        @endif

        {{-- Requester (Phase 8): confirm the work is done --}}
        @if ($has(A::CONFIRM_RESOLVED))
            <form method="POST" action="{{ route('tickets.confirm-resolved', $ticket) }}" style="{{ $box('#bfe6cc', 'var(--green-dim)') }}">
                @csrf
                <div class="lbl" style="font-size:16px;color:#0f6a31"><i class="fa-solid fa-circle-check"></i> Is the problem fixed?</div>
                <p class="hint" style="margin:0 0 12px">Maintenance says the work is done. If you agree, confirm and the ticket is closed right away.</p>
                <button class="btn btn-primary" style="background:var(--green);border-color:var(--green)">Confirm resolved</button>
            </form>
        @endif

        {{-- Requester (Phase 8): not fixed after all (inside the reopen window) --}}
        @if ($has(A::REOPEN))
            @php $deadline = \App\Services\TicketWorkflow::reopenDeadline($ticket); @endphp
            <form method="POST" action="{{ route('tickets.reopen', $ticket) }}" style="{{ $box('#bcd5ee', 'var(--sky)') }}">
                @csrf
                <div class="lbl" style="font-size:16px"><i class="fa-solid fa-rotate-left"></i> Reopen</div>
                <div class="field">
                    <label for="reopen_reason" class="lbl">What is still wrong? (required)</label>
                    <textarea id="reopen_reason" name="reopen_reason" rows="2" required style="{{ $area }}">{{ old('reopen_reason') }}</textarea>
                    @if ($errors->reopen->has('reopen_reason'))<p class="field-err">{{ $errors->reopen->first('reopen_reason') }}</p>@endif
                </div>
                @if ($deadline)
                    <p class="hint" style="margin:0 0 12px">You can reopen this ticket until {{ $deadline->timezone(config('app.timezone'))->format('M j, Y g:i A') }}.</p>
                @endif
                <button class="btn btn-primary">Reopen ticket</button>
            </form>
        @endif

        {{-- Requester (Phase 8): cancel, only before the ticket is assigned --}}
        @if ($has(A::CANCEL))
            <form method="POST" action="{{ route('tickets.cancel', $ticket) }}" style="{{ $box('#f3c9c5', 'var(--red-dim)') }}">
                @csrf
                <div class="lbl" style="font-size:16px;color:#9d2820"><i class="fa-solid fa-ban"></i> Cancel this ticket</div>
                <div class="field">
                    <label for="cancel_reason" class="lbl">Reason (required)</label>
                    <textarea id="cancel_reason" name="cancel_reason" rows="2" required style="{{ $area }}">{{ old('cancel_reason') }}</textarea>
                    @if ($errors->cancel->has('cancel_reason'))<p class="field-err">{{ $errors->cancel->first('cancel_reason') }}</p>@endif
                </div>
                <p class="hint" style="margin:0 0 12px">You can cancel only until the ticket is assigned. A cancelled ticket cannot be reopened.</p>
                <button class="btn btn-danger" onclick="return confirm('Cancel this ticket? This cannot be undone.')">Cancel ticket</button>
            </form>
        @endif

        {{-- Anyone involved: add a note --}}
        @if ($has(A::ADD_NOTE))
            <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}" style="{{ $box('var(--line)') }}">
                @csrf
                <div class="lbl" style="font-size:16px"><i class="fa-regular fa-note-sticky"></i> Add note</div>
                <div class="field">
                    <label for="note" class="lbl">Note for the history</label>
                    <textarea id="note" name="note" rows="2" required placeholder="Write a note for the history" style="{{ $area }}">{{ old('note') }}</textarea>
                    @if ($errors->note->has('note'))<p class="field-err">{{ $errors->note->first('note') }}</p>@endif
                </div>
                <button class="btn btn-primary">Add note</button>
            </form>
        @endif
    </div>
</section>
