@php
    $numInput = 'width:100%;height:46px;padding:0 15px;font-size:15px;border-radius:14px;border:1.5px solid var(--line);background:#f6f9fd;color:var(--text)';
@endphp

<x-app-layout>
    <x-slot name="header">Requisition Slip</x-slot>

    <div x-data="requisitionForm({
            selected: @js(array_map('strval', (array) old('request_types', []))),
            items: @js(array_values((array) old('items', []))),
        })">

        <div class="alert warn"><i class="fa-solid fa-calendar-check"></i><div>Note: All requests must be made three (3) working days in advance.</div></div>

        @error('form')<div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>@enderror

        <div class="two-col">
            <form method="POST" action="{{ route('requisitions.store') }}" class="card">
                @csrf

                <div class="row2">
                    <div class="field">
                        <label class="lbl" for="date_filed">Date filed</label>
                        <input id="date_filed" type="text" value="{{ now()->format('M j, Y') }}" disabled>
                    </div>

                    <div class="field">
                        <label class="lbl" for="date_needed">Date needed <span style="color:var(--red)">*</span></label>
                        <input id="date_needed" type="date" name="date_needed" value="{{ old('date_needed') }}" min="{{ $earliest->toDateString() }}" required>
                        <p class="hint">Earliest allowed: {{ $earliest->format('M j, Y (l)') }}</p>
                        @error('date_needed')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field">
                    <label class="lbl" for="department_id">Department/Office <span style="color:var(--red)">*</span></label>
                    <select id="department_id" name="department_id">
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected(old('department_id', $defaultDepartment) == $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                {{-- "Please check your request" --}}
                <fieldset class="field" style="border:0;padding:0;min-width:0">
                    <legend class="lbl">Please check your request <span style="color:var(--red)">*</span></legend>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px">
                        @foreach ($types as $key => $label)
                            <label style="display:flex;align-items:center;gap:10px;border:1.5px solid var(--line);border-radius:14px;padding:10px 12px;background:var(--mist);cursor:pointer">
                                <input type="checkbox" name="request_types[]" value="{{ $key }}" x-model="selected">
                                <span style="font-size:14px;font-weight:600;color:var(--navy)">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('request_types')<p class="field-err">{{ $message }}</p>@enderror
                    @error('request_types.*')<p class="field-err">{{ $message }}</p>@enderror

                    <div x-show="has('others')" style="display:none;margin-top:10px">
                        <label class="lbl" for="other_type">Others: please specify <span style="color:var(--red)">*</span></label>
                        <input id="other_type" type="text" name="other_type" value="{{ old('other_type') }}" maxlength="255">
                        @error('other_type')<p class="field-err">{{ $message }}</p>@enderror
                    </div>

                    <div x-show="has('cash')" style="display:none;margin-top:10px">
                        <label class="lbl" for="payee_name">Payee's name <span class="hint" style="display:inline">(for check only)</span></label>
                        <input id="payee_name" type="text" name="payee_name" value="{{ old('payee_name') }}" maxlength="255">
                        @error('payee_name')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </fieldset>

                {{-- QTY | DESCRIPTION | ACCOUNT TO BE CHARGED | TOTAL AMOUNT --}}
                <div style="border:1.5px solid var(--line);border-radius:18px;padding:16px;margin-bottom:14px;background:var(--mist)">
                    <div class="lbl" style="font-size:15px">Items <span style="color:var(--red)">*</span></div>

                    <template x-for="(item, i) in items" :key="i">
                        <div style="background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:10px">
                            <div style="display:grid;grid-template-columns:90px 1fr;gap:8px">
                                <input type="number" inputmode="numeric" min="1" step="1" :name="'items[' + i + '][quantity]'" x-model="item.quantity" placeholder="Qty" style="{{ $numInput }}">
                                <input type="text" :name="'items[' + i + '][description]'" x-model="item.description" maxlength="255" placeholder="Description">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 150px;gap:8px;margin-top:8px">
                                <input type="text" :name="'items[' + i + '][account_charged]'" x-model="item.account_charged" maxlength="255" placeholder="Account to be charged">
                                <input type="number" inputmode="decimal" min="0" step="0.01" :name="'items[' + i + '][amount]'" x-model="item.amount" placeholder="Total amount" style="{{ $numInput }}">
                            </div>
                            <button type="button" @click="removeItem(i)" x-show="items.length > 1" class="btn btn-sm btn-danger" style="margin-top:10px"><i class="fa-solid fa-trash-can"></i> Remove this row</button>
                        </div>
                    </template>

                    <button type="button" @click="addItem()" x-show="items.length < {{ $maxItems }}"
                            class="btn" style="width:100%;border-style:dashed;border-color:var(--blue);color:var(--deep)">
                        <i class="fa-solid fa-plus"></i> Add a row
                    </button>

                    <p style="text-align:right;font-weight:800;color:var(--navy);margin:12px 0 0">
                        Total: &#8369;<span x-text="total"></span>
                    </p>

                    @foreach ($errors->get('items*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="field-err">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <div class="field">
                    <label class="lbl" for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="2" maxlength="1000">{{ old('remarks') }}</textarea>
                    @error('remarks')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <p class="hint" style="margin:-4px 0 16px">You are filing this as the requester: <strong>{{ auth()->user()->name }}</strong>.</p>

                <button class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> SEND REQUISITION SLIP</button>
            </form>

            <aside class="card" style="align-self:start">
                <div class="card-h"><h3>What happens next</h3></div>
                <div class="pf-row"><span class="k">1. Filed</span><span class="mono">You get a reference number</span></div>
                <div class="pf-row"><span class="k">2. Noted</span><span class="mono">OIC/Dept. Head</span></div>
                <div class="pf-row"><span class="k">3. Recommended</span><span class="mono">Director, Finance &amp; Accounting</span></div>
                <div class="pf-row"><span class="k">4. Approved</span><span class="mono">School President</span></div>
            </aside>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('requisitionForm', (cfg) => ({
                selected: cfg.selected,
                items: cfg.items.length ? cfg.items : [{ quantity: 1, description: '', account_charged: '', amount: '' }],

                has(key) { return this.selected.map(String).includes(key); },

                get total() {
                    const sum = this.items.reduce((t, i) => t + (parseFloat(i.amount) || 0), 0);
                    return sum.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                addItem() { this.items.push({ quantity: 1, description: '', account_charged: '', amount: '' }); },
                removeItem(i) { this.items.splice(i, 1); },
            }));
        });
    </script>
</x-app-layout>
