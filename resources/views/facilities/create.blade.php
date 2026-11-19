@php
    // <input type="number"> is not covered by the global input rule in style.css, so give it the same look here
    $numInput = 'width:100%;height:46px;padding:0 15px;font-size:15px;border-radius:14px;border:1.5px solid var(--line);background:#f6f9fd;color:var(--text)';
@endphp

<x-app-layout>
    <x-slot name="header">Use of Facilities &amp; Equipment</x-slot>

    <div x-data="facilityForm({
            selected: @js(array_map('strval', (array) old('facility_ids', []))),
            othersId: @js((string) $othersId),
            items: @js(array_values((array) old('items', []))),
        })">

        <div class="alert warn"><i class="fa-solid fa-calendar-check"></i><div>Request must be made 3 days in advance. The request is still subject for approval.</div></div>

        @error('form')<div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>@enderror

        <div class="two-col">
            <form method="POST" action="{{ route('facilities.store') }}" class="card">
                @csrf

                <div class="row2">
                    <div class="field">
                        <label class="lbl" for="date_filed">Date filed</label>
                        <input id="date_filed" type="text" value="{{ now()->format('M j, Y') }}" disabled>
                    </div>

                    <div class="field">
                        <label class="lbl" for="department_id">Office/Department <span class="req" style="color:var(--red)">*</span></label>
                        <select id="department_id" name="department_id">
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected(old('department_id', $defaultDepartment) == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- The checkboxes printed on the form --}}
                <fieldset class="field" style="border:0;padding:0;min-width:0">
                    <legend class="lbl">Facility to use <span class="req" style="color:var(--red)">*</span></legend>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
                        @foreach ($facilities as $f)
                            <label style="display:flex;align-items:center;gap:10px;border:1.5px solid var(--line);border-radius:14px;padding:10px 12px;background:var(--mist);cursor:pointer">
                                <input type="checkbox" name="facility_ids[]" value="{{ $f->id }}" x-model="selected">
                                <span style="font-size:14px;font-weight:600;color:var(--navy)">{{ $f->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('facility_ids')<p class="field-err">{{ $message }}</p>@enderror
                    @error('facility_ids.*')<p class="field-err">{{ $message }}</p>@enderror

                    <div x-show="othersOn" style="display:none;margin-top:10px">
                        <label class="lbl" for="other_facility">Others: which facility? <span class="req" style="color:var(--red)">*</span></label>
                        <input id="other_facility" type="text" name="other_facility" value="{{ old('other_facility') }}" maxlength="255" placeholder="e.g. Covered court">
                        @error('other_facility')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </fieldset>

                <div class="field">
                    <label class="lbl" for="purpose">Purpose <span class="req" style="color:var(--red)">*</span></label>
                    <input id="purpose" type="text" name="purpose" value="{{ old('purpose') }}" maxlength="255" required placeholder="e.g. Faculty meeting, Grade 10 recollection, Intramurals practice">
                    @error('purpose')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="row2">
                    <div class="field">
                        <label class="lbl" for="persons_count">No. of person to use <span class="req" style="color:var(--red)">*</span></label>
                        <input id="persons_count" type="number" inputmode="numeric" min="1" max="5000" step="1" name="persons_count" value="{{ old('persons_count') }}" required style="{{ $numInput }}">
                        @error('persons_count')<p class="field-err">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label class="lbl" for="time_needed">Time <span class="req" style="color:var(--red)">*</span></label>
                        <input id="time_needed" type="time" name="time_needed" value="{{ old('time_needed') }}" required>
                        @error('time_needed')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field">
                    <label class="lbl" for="date_needed">Date needed <span class="req" style="color:var(--red)">*</span></label>
                    <input id="date_needed" type="date" name="date_needed" value="{{ old('date_needed') }}" min="{{ $earliest->toDateString() }}" required>
                    <p class="hint">Earliest allowed: {{ $earliest->format('M j, Y (l)') }}</p>
                    @error('date_needed')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                {{-- Quantity | Equipment/Materials Needed --}}
                <div style="border:1.5px solid var(--line);border-radius:18px;padding:16px;margin-bottom:14px;background:var(--mist)">
                    <div class="lbl" style="font-size:15px">Equipment/Materials needed <span class="hint" style="display:inline">(optional)</span></div>

                    <template x-for="(item, i) in items" :key="i">
                        <div style="background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:10px">
                            <div style="display:grid;grid-template-columns:110px 1fr;gap:8px">
                                <input type="number" inputmode="numeric" min="1" step="1" :name="'items[' + i + '][quantity]'" x-model="item.quantity" placeholder="Quantity" style="{{ $numInput }}">
                                <input type="text" :name="'items[' + i + '][description]'" x-model="item.description" maxlength="255" placeholder="e.g. Monobloc chairs, Sound system, Projector">
                            </div>
                            <button type="button" @click="removeItem(i)" class="btn btn-sm btn-danger" style="margin-top:10px"><i class="fa-solid fa-trash-can"></i> Remove this row</button>
                        </div>
                    </template>

                    <button type="button" @click="addItem()" x-show="items.length < {{ $maxItems }}"
                            class="btn" style="width:100%;border-style:dashed;border-color:var(--blue);color:var(--deep)">
                        <i class="fa-solid fa-plus"></i> Add a row
                    </button>

                    @foreach ($errors->get('items*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="field-err">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <p class="hint" style="font-style:italic;font-weight:600;margin:0 0 16px">
                    I hereby certify that I/we shall be responsible for the proper use of school facilities and retrieval of equipment/materials.
                </p>
                <p class="hint" style="margin:-8px 0 16px">You are filing this as the Person Responsible: <strong>{{ auth()->user()->name }}</strong>.</p>

                <button class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> SEND REQUEST</button>
            </form>

            {{-- What happens next --}}
            <aside class="card" style="align-self:start">
                <div class="card-h"><h3>What happens next</h3></div>
                <div class="pf-row"><span class="k">1. Filed</span><span class="mono">You get a reference number</span></div>
                <div class="pf-row"><span class="k">2. Endorsed</span><span class="mono">Office-In-Charge</span></div>
                <div class="pf-row"><span class="k">3. Noted</span><span class="mono">Property Custodian</span></div>
                <div class="pf-row"><span class="k">4. Approved</span><span class="mono">School President</span></div>
                <div class="pf-row"><span class="k">5. Returned</span><span class="mono">Property Custodian records it</span></div>
                <p class="hint">The request is still subject for approval.</p>
            </aside>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('facilityForm', (cfg) => ({
                selected: cfg.selected,
                othersId: cfg.othersId,
                items: cfg.items.length ? cfg.items : [{ quantity: 1, description: '' }],

                // the "Others: ____" line only appears when "Others" is ticked
                get othersOn() {
                    return this.othersId !== '' && this.selected.map(String).includes(this.othersId);
                },

                addItem() {
                    this.items.push({ quantity: 1, description: '' });
                },
                removeItem(i) {
                    this.items.splice(i, 1);
                },
            }));
        });
    </script>
</x-app-layout>