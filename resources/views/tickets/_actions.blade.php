@use('App\Services\TicketActions', 'A')

@php
    $has = fn (string $action) => in_array($action, $actions, true);
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-base';
    $btn = 'min-h-[48px] w-full rounded-xl px-6 py-3 text-base font-bold text-white sm:w-auto';
@endphp

{{-- Action panel (Phase 4A). Each action shows ONLY when the status allows it AND the user has the capability;
     the controllers check both again. Errors go to one bag per form (approve, disapprove, assign, flag, clear, note). --}}
<section class="rounded-xl bg-white p-4 shadow-sm" id="actions">
    <h3 class="mb-1 text-lg font-bold text-gray-900">Actions</h3>

    @if (session('error'))
        <div class="mb-3 rounded-lg bg-red-100 p-3 font-semibold text-red-800" role="alert">{{ session('error') }}</div>
    @endif

    @if ($actions === [])
        <p class="text-sm text-gray-500">
            @if ($ticket->status->isTerminal())
                This ticket is {{ $ticket->status->label() }}. No further actions are possible.
            @else
                There is nothing for you to do on this ticket right now.
            @endif
        </p>
    @endif

    <div class="space-y-4">

        {{-- Admin: Submitted -> Pending Authorization --}}
        @if ($has(A::SEND_FOR_AUTHORIZATION))
            <form method="POST" action="{{ route('tickets.send-for-authorization', $ticket) }}" class="rounded-lg border border-gray-200 p-3">
                @csrf
                <div class="mb-2 text-sm text-gray-600">Put this ticket in the authorizer's queue.</div>
                <button class="{{ $btn }} bg-yellow-600 hover:bg-yellow-700">Send for authorization</button>
            </form>
        @endif

        {{-- Authorizer: Approve / Disapprove --}}
        @if ($has(A::APPROVE))
            <form method="POST" action="{{ route('tickets.approve', $ticket) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-green-200 bg-green-50 p-3">
                @csrf
                <div class="font-bold text-green-900">Approve</div>
                <div>
                    <label for="remarks" class="block text-sm font-semibold text-gray-700">Remarks (optional)</label>
                    <textarea id="remarks" name="remarks" rows="2" class="{{ $input }}">{{ old('remarks') }}</textarea>
                    @if ($errors->approve->has('remarks'))<p class="mt-1 text-sm text-red-600">{{ $errors->approve->first('remarks') }}</p>@endif
                </div>
                <div>
                    <div class="text-sm font-semibold text-gray-700">Signed file (optional)</div>
                    <x-file-picker name="files" />
                    @foreach ($errors->approve->get('files') as $message)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@endforeach
                    @foreach ($errors->approve->get('files.*') as $messages)
                        @foreach ((array) $messages as $message)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@endforeach
                    @endforeach
                </div>
                <p class="text-xs text-gray-600">The app records who authorized and when. It does not replace the official signatures: keep the signed paper or scan on file.</p>
                <button class="{{ $btn }} bg-green-600 hover:bg-green-700">Approve</button>
            </form>
        @endif

        @if ($has(A::DISAPPROVE))
            <form method="POST" action="{{ route('tickets.disapprove', $ticket) }}" class="space-y-3 rounded-lg border border-red-200 bg-red-50 p-3">
                @csrf
                <div class="font-bold text-red-900">Disapprove</div>
                <div>
                    <label for="reason" class="block text-sm font-semibold text-gray-700">Reason (required)</label>
                    <textarea id="reason" name="reason" rows="2" required class="{{ $input }}">{{ old('reason') }}</textarea>
                    @if ($errors->disapprove->has('reason'))<p class="mt-1 text-sm text-red-600">{{ $errors->disapprove->first('reason') }}</p>@endif
                </div>
                <button class="{{ $btn }} bg-red-600 hover:bg-red-700">Disapprove</button>
            </form>
        @endif

        {{-- Admin: Assign / Reassign --}}
        @if ($has(A::ASSIGN) || $has(A::REASSIGN))
            @php $reassign = $has(A::REASSIGN); @endphp
            <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="space-y-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                @csrf
                <div class="font-bold text-indigo-900">{{ $reassign ? 'Reassign' : 'Assign' }}</div>
                @if ($reassign)
                    <div class="text-sm text-gray-700">Currently assigned to <strong>{{ $ticket->assignee?->name }}</strong>.</div>
                @endif
                <div>
                    <label for="assignee_id" class="block text-sm font-semibold text-gray-700">Maintenance staff</label>
                    <select id="assignee_id" name="assignee_id" required class="{{ $input }}">
                        <option value="">Choose staff…</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((int) old('assignee_id') === $member->id)>
                                {{ $member->name }} — {{ $member->open_tickets_count }} open {{ \Illuminate\Support\Str::plural('ticket', $member->open_tickets_count) }}
                            </option>
                        @endforeach
                    </select>
                    @if ($errors->assign->has('assignee_id'))<p class="mt-1 text-sm text-red-600">{{ $errors->assign->first('assignee_id') }}</p>@endif
                </div>
                <div>
                    <label for="assign_note" class="block text-sm font-semibold text-gray-700">Note {{ $reassign ? '(required: why is it being reassigned?)' : '(optional)' }}</label>
                    <textarea id="assign_note" name="assign_note" rows="2" @required($reassign) class="{{ $input }}">{{ old('assign_note') }}</textarea>
                    @if ($errors->assign->has('assign_note'))<p class="mt-1 text-sm text-red-600">{{ $errors->assign->first('assign_note') }}</p>@endif
                </div>
                <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">{{ $reassign ? 'Reassign' : 'Assign' }}</button>
            </form>
        @endif

        {{-- Emergency flag --}}
        @if ($has(A::FLAG_EMERGENCY))
            <form method="POST" action="{{ route('tickets.emergency.flag', $ticket) }}" class="space-y-3 rounded-lg border border-red-200 p-3">
                @csrf
                <div class="font-bold text-red-800">🚨 Flag emergency</div>
                <div>
                    <label for="emergency_reason" class="block text-sm font-semibold text-gray-700">Reason (required)</label>
                    <textarea id="emergency_reason" name="emergency_reason" rows="2" required class="{{ $input }}">{{ old('emergency_reason') }}</textarea>
                    @if ($errors->flag->has('emergency_reason'))<p class="mt-1 text-sm text-red-600">{{ $errors->flag->first('emergency_reason') }}</p>@endif
                </div>
                <button class="{{ $btn }} bg-red-700 hover:bg-red-800">Flag emergency</button>
            </form>
        @endif

        @if ($has(A::CLEAR_EMERGENCY))
            <form method="POST" action="{{ route('tickets.emergency.clear', $ticket) }}" class="space-y-3 rounded-lg border border-gray-300 p-3">
                @csrf
                <div class="font-bold text-gray-800">Clear emergency</div>
                <div>
                    <label for="clear_note" class="block text-sm font-semibold text-gray-700">Note (optional)</label>
                    <textarea id="clear_note" name="clear_note" rows="2" class="{{ $input }}">{{ old('clear_note') }}</textarea>
                    @if ($errors->clear->has('clear_note'))<p class="mt-1 text-sm text-red-600">{{ $errors->clear->first('clear_note') }}</p>@endif
                </div>
                <p class="text-xs text-gray-600">The ticket goes back to its original place in the queue (by the time it was submitted).</p>
                <button class="{{ $btn }} bg-gray-700 hover:bg-gray-800">Clear emergency</button>
            </form>
        @endif

        {{-- Anyone involved: add a note --}}
        @if ($has(A::ADD_NOTE))
            <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}" class="space-y-3 rounded-lg border border-gray-200 p-3">
                @csrf
                <div class="font-bold text-gray-800">Add note</div>
                <div>
                    <label for="note" class="sr-only">Note</label>
                    <textarea id="note" name="note" rows="2" required placeholder="Write a note for the history" class="{{ $input }}">{{ old('note') }}</textarea>
                    @if ($errors->note->has('note'))<p class="mt-1 text-sm text-red-600">{{ $errors->note->first('note') }}</p>@endif
                </div>
                <button class="{{ $btn }} bg-blue-600 hover:bg-blue-700">Add note</button>
            </form>
        @endif
    </div>
</section>
