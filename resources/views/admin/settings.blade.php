<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">System settings</h2>
    </x-slot>

    <div class="content p6-narrow">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif

        <form method="POST" action="{{ route('admin.settings.update') }}" class="card">
            @csrf
            @method('PUT')

            <h3 class="p6-card-t">Target completion time (SLA)</h3>
            <p class="p6-card-s">
                Every ticket gets a target date: its submission time plus the hours below (calendar hours, not working hours).
                A pending ticket past its target date is shown as <b>Overdue</b>.
            </p>

            <div class="row2">
                <div class="field">
                    <label for="sla_hours_routine">Routine ticket (hours)</label>
                    <input id="sla_hours_routine" type="number" name="sla_hours_routine" min="1" max="8760" required inputmode="numeric"
                           value="{{ old('sla_hours_routine', $values['sla_hours_routine']) }}">
                    @error('sla_hours_routine')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="sla_hours_emergency">Emergency ticket (hours)</label>
                    <input id="sla_hours_emergency" type="number" name="sla_hours_emergency" min="1" max="8760" required inputmode="numeric"
                           value="{{ old('sla_hours_emergency', $values['sla_hours_emergency']) }}">
                    @error('sla_hours_emergency')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label for="reopen_window_days">Reopen window (days after closing)</label>
                <input id="reopen_window_days" type="number" name="reopen_window_days" min="0" max="365" required inputmode="numeric"
                       value="{{ old('reopen_window_days', $values['reopen_window_days']) }}">
                <div class="p6-hint">Enter 0 to turn reopening off. Used by the reopen feature in a later phase.</div>
                @error('reopen_window_days')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="autoclose_waiting_days">Auto-close after no answer (days)</label>
                <input id="autoclose_waiting_days" type="number" name="autoclose_waiting_days" min="0" max="365" inputmode="numeric"
                       value="{{ old('autoclose_waiting_days', $values['autoclose_waiting_days']) }}">
                <div class="p6-hint">When staff ask the requester for information, the ticket waits and its target time is paused. If nobody answers for this many days the ticket is closed (as Cancelled). Enter 0 to turn it off.</div>
                @error('autoclose_waiting_days')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <label class="p6-check">
                <input type="checkbox" name="recalculate_open" value="1">
                <span>Also re-date tickets that are still pending
                    <small>Leave this off and only new tickets will use the new hours.</small>
                </span>
            </label>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            </div>
        </form>
    </div>
</x-app-layout>
