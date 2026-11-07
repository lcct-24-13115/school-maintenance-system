<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">Equipment</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert err" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>@foreach ($errors->all() as $message){{ $message }} @endforeach</span>
            </div>
        @endif

        <div style="margin-bottom:14px">
            <a href="{{ route('admin.pm-schedules.index') }}" class="btn"><i class="fa-solid fa-arrow-left"></i> PM schedules</a>
        </div>

        <form method="POST" action="{{ route('admin.equipment.store') }}" class="card">
            @csrf
            <h3 class="p6-card-t">Add equipment</h3>

            <div class="row2">
                <div class="field">
                    <label for="new-name">Name</label>
                    <input id="new-name" type="text" name="name" maxlength="150" required value="{{ old('name') }}" placeholder="Split-type Aircon (Room 101)">
                </div>
                <div class="field">
                    <label for="new-facility">Facility</label>
                    <select id="new-facility" name="facility_id" required>
                        <option value="">Choose a facility</option>
                        @foreach ($facilities as $f)
                            <option value="{{ $f->id }}" @selected((string) old('facility_id') === (string) $f->id)>{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="new-category">Category <span class="hint" style="display:inline">(optional)</span></label>
                    <input id="new-category" type="text" name="category" maxlength="100" value="{{ old('category') }}" placeholder="Air Conditioning">
                </div>
                <div class="field">
                    <label for="new-serial">Serial no. <span class="hint" style="display:inline">(optional)</span></label>
                    <input id="new-serial" type="text" name="serial_no" maxlength="100" value="{{ old('serial_no') }}">
                </div>
            </div>

            <div class="field">
                <label for="new-notes">Notes <span class="hint" style="display:inline">(optional)</span></label>
                <textarea id="new-notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add equipment</button>
            </div>
        </form>

        <div class="card" style="padding:0">
            @if ($equipment->isEmpty())
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-screwdriver-wrench"></i>No equipment yet. Add the first one above.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Equipment</th>
                                <th>Facility</th>
                                <th>Serial no.</th>
                                <th>PM schedules</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>

                        @foreach ($equipment as $e)
                            @php $confirm = "Delete \"{$e->name}\"?"; @endphp
                            <tbody x-data="{ edit: false }">
                                <tr>
                                    <td data-label="Equipment" style="word-break:break-word">
                                        <b>{{ $e->name }}</b>
                                        @if ($e->category)<div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $e->category }}</div>@endif
                                    </td>
                                    <td data-label="Facility">{{ $e->facility?->name }}</td>
                                    <td data-label="Serial no.">{{ $e->serial_no ?: '—' }}</td>
                                    <td data-label="PM schedules">{{ $e->pm_schedules_count }}</td>
                                    <td data-label="Actions" style="text-align:right;white-space:nowrap">
                                        <button type="button" class="btn btn-sm" @click="edit = !edit"><i class="fa-solid fa-pen"></i> Edit</button>
                                        <form method="POST" action="{{ route('admin.equipment.destroy', $e) }}" style="display:inline"
                                              onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }})">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr x-show="edit" x-cloak>
                                    <td colspan="5">
                                        <form method="POST" action="{{ route('admin.equipment.update', $e) }}">
                                            @csrf
                                            @method('PUT')
                                            <div class="row2">
                                                <div class="field">
                                                    <label for="n-{{ $e->id }}">Name</label>
                                                    <input id="n-{{ $e->id }}" type="text" name="name" maxlength="150" required value="{{ $e->name }}">
                                                </div>
                                                <div class="field">
                                                    <label for="f-{{ $e->id }}">Facility</label>
                                                    <select id="f-{{ $e->id }}" name="facility_id" required>
                                                        @foreach ($facilities as $f)
                                                            <option value="{{ $f->id }}" @selected($e->facility_id === $f->id)>{{ $f->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row2">
                                                <div class="field">
                                                    <label for="c-{{ $e->id }}">Category</label>
                                                    <input id="c-{{ $e->id }}" type="text" name="category" maxlength="100" value="{{ $e->category }}">
                                                </div>
                                                <div class="field">
                                                    <label for="s-{{ $e->id }}">Serial no.</label>
                                                    <input id="s-{{ $e->id }}" type="text" name="serial_no" maxlength="100" value="{{ $e->serial_no }}">
                                                </div>
                                            </div>
                                            <div class="field">
                                                <label for="o-{{ $e->id }}">Notes</label>
                                                <textarea id="o-{{ $e->id }}" name="notes" rows="2" maxlength="2000">{{ $e->notes }}</textarea>
                                            </div>
                                            <div class="p6-save">
                                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Save</button>
                                                <button type="button" class="btn btn-sm" @click="edit = false">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </tbody>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        @include('partials.pager', ['paginator' => $equipment])
    </div>
</x-app-layout>