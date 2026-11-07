@php
    // <input type="number"> is not covered by the global input rule in style.css, so give it the same look here
    $numInput = 'width:100%;height:46px;padding:0 15px;font-size:15px;border-radius:14px;border:1.5px solid var(--line);background:#f6f9fd;color:var(--text)';
    $vh = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
@endphp

<x-app-layout>
    <x-slot name="header">Request for Repairs &amp; Improvement (FO-08)</x-slot>

    <div x-data="repairForm({
            priority: @js(old('priority', 'first_come_first_served')),
            requestType: @js((string) old('request_type', '')),
            items: @js(array_values((array) old('items', []))),
        })">

        <div class="alert warn"><i class="fa-solid fa-calendar-check"></i><div>Request must be made 5 working days in advance.</div></div>
        <x-help-link topic="Sending a repair request" row />

        @error('form')<div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>@enderror

        <div class="two-col">
            <form method="POST" action="{{ route('repairs.store') }}" enctype="multipart/form-data" class="card">
                @csrf

                <div class="row2">
                    <div class="field">
                        <label class="lbl" for="department_id">From: (Department/Office)</label>
                        <select id="department_id" name="department_id">
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected(old('department_id', $defaultDepartment) == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<p class="field-err">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label class="lbl" for="request_type">Request type <span class="hint" style="display:inline">(optional)</span></label>
                        <select id="request_type" name="request_type" x-model="requestType">
                            <option value="">None (a repair or service)</option>
                            @foreach ($requestTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('request_type')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="hint" style="margin:-6px 0 14px">Choose a request type only if you are asking for materials, equipment or supplies. The category list below will match it.</p>

                <div class="field">
                    <label class="lbl" for="category_id">Maintenance category</label>
                    <select id="category_id" name="category_id" x-ref="category">
                        <option value="">— Choose one —</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" data-type="{{ $c->type }}" @selected(old('category_id') == $c->id)>{{ $c->name }}@if ($c->is_locked && $c->name === \App\Models\Category::OTHER) ({{ $requestTypes[$c->type] ?? ucfirst($c->type) }})@endif</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                {{-- Items: only when a request type is chosen. Rows sent without a type are ignored by the server. --}}
                <div x-show="requestType !== ''" style="display:none;border:1.5px solid var(--line);border-radius:18px;padding:16px;margin-bottom:14px;background:var(--mist)">
                    <div class="lbl" style="font-size:15px">Items needed <span class="hint" style="display:inline">(optional)</span></div>

                    <template x-for="(item, i) in items" :key="i">
                        <div style="background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:10px">
                            <input type="text" :name="'items[' + i + '][item_name]'" x-model="item.item_name" maxlength="255" placeholder="Item name" style="margin-bottom:8px">
                            <div class="row2" style="margin-bottom:8px">
                                <input type="number" inputmode="numeric" min="1" step="1" :name="'items[' + i + '][quantity]'" x-model="item.quantity" placeholder="Quantity" style="{{ $numInput }}">
                                <input type="text" :name="'items[' + i + '][unit]'" x-model="item.unit" maxlength="50" placeholder="Unit (pc, box, set)">
                            </div>
                            <input type="text" :name="'items[' + i + '][specification]'" x-model="item.specification" maxlength="1000" placeholder="Specification (size, brand, model...)">
                            <button type="button" @click="removeItem(i)" class="btn btn-sm btn-danger" style="margin-top:10px"><i class="fa-solid fa-trash-can"></i> Remove this item</button>
                        </div>
                    </template>

                    <button type="button" @click="addItem()" x-show="items.length < {{ \App\Http\Requests\Forms\StoreRepairRequestRequest::MAX_ITEMS }}"
                            class="btn" style="width:100%;border-style:dashed;border-color:var(--blue);color:var(--deep)">
                        <i class="fa-solid fa-plus"></i> Add an item
                    </button>

                    @foreach ($errors->get('items*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="field-err">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <div class="field">
                    <label class="lbl" for="location">Where is it? (building / room)</label>
                    <input id="location" type="text" name="location" value="{{ old('location') }}" placeholder="e.g. Grade 5 Room 204">
                    @error('location')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl" for="job_description">Job Description</label>
                    <textarea id="job_description" name="job_description" rows="3" placeholder="What needs to be fixed or improved?">{{ old('job_description') }}</textarea>
                    @error('job_description')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl" for="reason">Reason for Request</label>
                    <textarea id="reason" name="reason" rows="3">{{ old('reason') }}</textarea>
                    @error('reason')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <fieldset class="field" style="border:0;padding:0;min-width:0">
                    <legend class="lbl">Priority</legend>
                    <div class="tile-grid two">
                        <label class="tile-opt" :class="priority === 'first_come_first_served' ? 'active' : ''">
                            <input type="radio" name="priority" value="first_come_first_served" x-model="priority" style="{{ $vh }}">
                            <i class="fa-solid fa-list-ol"></i>
                            <span>First Come First Served</span>
                        </label>
                        <label class="tile-opt" :class="priority === 'emergency' ? 'active' : ''" :style="priority === 'emergency' ? 'border-color:var(--red)' : ''">
                            <input type="radio" name="priority" value="emergency" x-model="priority" style="{{ $vh }}">
                            <i class="fa-solid fa-triangle-exclamation" :style="priority === 'emergency' ? 'color:var(--red)' : ''"></i>
                            @if ($canFlag)
                                <span>Emergency</span>
                            @else
                                <span>Ask for emergency review</span>
                            @endif
                        </label>
                    </div>
                    @unless ($canFlag)
                        <div class="alert warn" x-show="priority === 'emergency'" style="display:none;margin:12px 0 0">
                            <i class="fa-solid fa-circle-info"></i>
                            <div>Your ticket will be filed as a routine request. Maintenance will see that you asked for an emergency review and your reason below.</div>
                        </div>
                    @endunless
                    @error('priority')<p class="field-err">{{ $message }}</p>@enderror
                </fieldset>

                <div class="field" x-show="priority !== 'emergency' || {{ $emergencyBypass ? 'false' : 'true' }}">
                    <label class="lbl" for="needed_by">Date needed</label>
                    <input id="needed_by" type="date" name="needed_by" value="{{ old('needed_by') }}" min="{{ $earliest->toDateString() }}">
                    <p class="hint">Earliest allowed: {{ $earliest->format('M j, Y (l)') }}</p>
                    @error('needed_by')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl">Photos &amp; documents <span class="hint" style="display:inline">(optional)</span></label>
                    <x-file-picker name="files" />
                    @if ($errors->has('files') || $errors->has('files.*'))
                        <p class="hint">Your browser does not keep chosen files after an error, so please pick them again.</p>
                    @endif
                    @error('files')<p class="field-err">{{ $message }}</p>@enderror
                    @foreach ($errors->get('files.*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="field-err">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <label class="check" style="display:flex;align-items:flex-start;gap:12px;border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;margin-bottom:18px;background:var(--mist)">
                    <input type="checkbox" name="is_confidential" value="1" @checked(old('is_confidential')) style="margin-top:4px">
                    <span>
                        <span style="display:block;font-size:15px;font-weight:700;color:var(--navy)"><i class="fa-solid fa-lock"></i> Mark as confidential</span>
                        <span style="display:block;font-size:13px;font-weight:400;color:var(--text-2)">Only you, the Head of Maintenance and the staff member assigned to it can see this ticket and its files.</span>
                    </span>
                </label>

                <button class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> SEND REQUEST</button>
            </form>

            {{-- What happens next --}}
            <aside class="card" style="align-self:start">
                <div class="card-h"><h3>What happens next</h3></div>
                <div class="pf-row"><span class="k">1. Filed</span><span class="mono">You get a ticket number</span></div>
                <div class="pf-row"><span class="k">2. Authorized</span><span class="mono">The Head of Maintenance approves</span></div>
                <div class="pf-row"><span class="k">3. Assigned</span><span class="mono">Maintenance picks it up</span></div>
                <div class="pf-row"><span class="k">4. Done</span><span class="mono">Work is completed and closed</span></div>
                <p class="hint">You can follow every step on the ticket page.</p>
            </aside>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('repairForm', (cfg) => ({
                priority: cfg.priority,
                requestType: cfg.requestType,
                items: cfg.items,
                allOptions: [],

                init() {
                    // wait one tick so every x-ref inside the form exists
                    this.$nextTick(() => {
                        this.allOptions = Array.from(this.$refs.category.querySelectorAll('option[data-type]'));
                        this.filterCategories();
                    });
                    this.$watch('requestType', () => this.filterCategories());
                },

                // Show only the categories of the chosen request type ("None" shows all of them).
                filterCategories() {
                    const select = this.$refs.category;
                    const current = select.value;
                    this.allOptions.forEach(o => o.remove());
                    const keep = this.allOptions.filter(o => this.requestType === '' || o.dataset.type === this.requestType);
                    keep.forEach(o => select.appendChild(o));
                    select.value = keep.some(o => o.value === current) ? current : '';
                },

                addItem() {
                    this.items.push({ item_name: '', quantity: 1, unit: '', specification: '' });
                },
                removeItem(i) {
                    this.items.splice(i, 1);
                },
            }));
        });
    </script>
</x-app-layout>
