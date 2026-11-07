@php
    $isNew = ! $schedule->exists;
    $action = $isNew ? route('admin.pm-schedules.store') : route('admin.pm-schedules.update', $schedule);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">{{ $isNew ? 'New PM schedule' : 'Edit PM schedule' }}</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if ($errors->any())
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>Please fix the highlighted fields below.</span></div>
        @endif

        <form method="POST" action="{{ $action }}" class="card"
              x-data="{
                  facility: {{ \Illuminate\Support\Js::from((string) old('facility_id', $schedule->facility_id)) }},
                  equipment: {{ \Illuminate\Support\Js::from((string) old('equipment_id', $schedule->equipment_id)) }},
                  all: {{ \Illuminate\Support\Js::from($equipmentOptions) }},
                  freq: {{ \Illuminate\Support\Js::from((string) old('frequency_type', $schedule->frequency_type)) }}
              }">
            @csrf
            @if (! $isNew) @method('PUT') @endif

            <div class="field">
                <label for="title">Title</label>
                <input id="title" type="text" name="title" maxlength="150" required value="{{ old('title', $schedule->title) }}" placeholder="Aircon Cleaning">
                @error('title')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="description">Description <span class="hint" style="display:inline">(optional, goes into the ticket)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="2000">{{ old('description', $schedule->description) }}</textarea>
                @error('description')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="row2">
                <div class="field">
                    <label for="facility_id">Facility</label>
                    <select id="facility_id" name="facility_id" required data-native x-model="facility" @change="equipment = ''">
                        <option value="">Choose a facility</option>
                        @foreach ($facilities as $f)
                            <option value="{{ $f->id }}">{{ $f->name }}</option>
                        @endforeach
                    </select>
                    @error('facility_id')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="equipment_id">Equipment <span class="hint" style="display:inline">(optional)</span></label>
                    <select id="equipment_id" name="equipment_id" data-native x-model="equipment">
                        <option value="">Whole facility / no specific equipment</option>
                        <template x-for="o in all.filter(e => String(e.facility_id) === String(facility))" :key="o.id">
                            <option :value="o.id" x-text="o.name" :selected="String(o.id) === String(equipment)"></option>
                        </template>
                    </select>
                    @error('equipment_id')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="frequency_type">Repeats</label>
                    <select id="frequency_type" name="frequency_type" required data-native x-model="freq">
                        @foreach ($frequencies as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency_type')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="frequency_interval">
                        Every how many?
                        <span class="hint" style="display:inline" x-text="freq === 'custom' ? '(days)' : '(1 = every time; 2 = every other time)'"></span>
                    </label>
                    <input id="frequency_interval" type="number" name="frequency_interval" min="1" max="365" required value="{{ old('frequency_interval', $schedule->frequency_interval) }}">
                    @error('frequency_interval')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="start_date">Start date <span class="hint" style="display:inline">(first due date)</span></label>
                    <input id="start_date" type="date" name="start_date" required value="{{ old('start_date', $schedule->start_date?->toDateString()) }}">
                    @error('start_date')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="end_date">End date <span class="hint" style="display:inline">(optional)</span></label>
                    <input id="end_date" type="date" name="end_date" value="{{ old('end_date', $schedule->end_date?->toDateString()) }}">
                    @error('end_date')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="lead_time_days">Make the ticket this many days before the due date</label>
                    <input id="lead_time_days" type="number" name="lead_time_days" min="0" max="365" required value="{{ old('lead_time_days', $schedule->lead_time_days) }}">
                    @error('lead_time_days')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="ticket_priority">Ticket priority</label>
                    <select id="ticket_priority" name="ticket_priority" required>
                        @foreach ($priorities as $value => $label)
                            <option value="{{ $value }}" @selected(old('ticket_priority', $schedule->ticket_priority) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('ticket_priority')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="assigned_to">Assigned to <span class="hint" style="display:inline">(optional)</span></label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">Nobody yet</option>
                        @foreach ($assignees as $a)
                            <option value="{{ $a->id }}" @selected((string) old('assigned_to', $schedule->assigned_to) === (string) $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_to')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="category_id">Ticket category <span class="hint" style="display:inline">(optional)</span></label>
                    <select id="category_id" name="category_id">
                        <option value="">None</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected((string) old('category_id', $schedule->category_id) === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label style="display:flex;gap:10px;align-items:center;cursor:pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $schedule->is_active))>
                    <span>Active (an inactive schedule makes no tickets)</span>
                </label>
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> {{ $isNew ? 'Create schedule' : 'Save changes' }}</button>
                <a href="{{ route('admin.pm-schedules.index') }}" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>