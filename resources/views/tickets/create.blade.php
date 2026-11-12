@php
    $vh = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
    $user = auth()->user();
@endphp

<x-app-layout>
    <x-slot name="header">New ticket</x-slot>

    <div x-data="ticketForm({
            priority: @js(old('priority', 'first_come_first_served')),
            department: @js((string) old('assigned_department_id', '')),
        })">

        <div class="alert warn"><i class="fa-solid fa-screwdriver-wrench"></i>
            <div>Need a repair, materials, equipment or supplies from the Maintenance Office? Use the <a href="{{ route('repairs.create') }}" style="font-weight:700;text-decoration:underline">repair request form (FO-08)</a> instead.</div>
        </div>

        @error('form')<div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>@enderror
        @error('assigned_to')<div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>@enderror

        <div class="two-col">
            <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" class="card">
                @csrf

                @if ($allDepartments)
                    <div class="field">
                        <label class="lbl" for="from_department_id">From: (your Department/Office)</label>
                        <select id="from_department_id" name="from_department_id">
                            <option value="">— Choose one —</option>
                            @foreach ($allDepartments as $d)
                                <option value="{{ $d->id }}" @selected(old('from_department_id') == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @error('from_department_id')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="field">
                    <label class="lbl" for="title">Title</label>
                    <input id="title" type="text" name="title" value="{{ old('title') }}" maxlength="150" placeholder="A short summary, e.g. Request for transcript of records">
                    @error('title')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl" for="description">Description</label>
                    <textarea id="description" name="description" rows="4" maxlength="2000" placeholder="Tell the office what you need and anything that helps them.">{{ old('description') }}</textarea>
                    @error('description')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl" for="assigned_department_id">To: (Department/Office that receives it)</label>
                    <select id="assigned_department_id" name="assigned_department_id" x-model="department">
                        <option value="">— Choose a department —</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_department_id')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="lbl" for="category_id">Category</label>
                    <select id="category_id" name="category_id" x-ref="category" @change="filterSubcategories()">
                        <option value="">— Choose one —</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" data-department="{{ $c->department_id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <p class="hint" x-show="department === ''" style="display:none">Choose the department first. Only its categories are shown.</p>
                    @error('category_id')<p class="field-err">{{ $message }}</p>@enderror
                </div>

                {{-- Optional subcategory: only shown when the chosen category has some. --}}
                <div class="field" x-show="hasSubs" style="display:none">
                    <label class="lbl" for="subcategory_id">Subcategory <span class="hint" style="display:inline">(optional)</span></label>
                    <select id="subcategory_id" name="subcategory_id" x-ref="subcategory">
                        <option value="">— Not sure / none —</option>
                        @foreach ($subcategories as $sc)
                            <option value="{{ $sc->id }}" data-category="{{ $sc->category_id }}" @selected(old('subcategory_id') == $sc->id)>{{ $sc->name }}</option>
                        @endforeach
                    </select>
                    @error('subcategory_id')<p class="field-err">{{ $message }}</p>@enderror
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
                            <span>{{ $canFlag ? 'Emergency' : 'Ask for emergency review' }}</span>
                        </label>
                    </div>
                    @unless ($canFlag)
                        <div class="alert warn" x-show="priority === 'emergency'" style="display:none;margin:12px 0 0">
                            <i class="fa-solid fa-circle-info"></i>
                            <div>Your ticket will be filed as a routine request. The office will see that you asked for an emergency review.</div>
                        </div>
                    @endunless
                    @error('priority')<p class="field-err">{{ $message }}</p>@enderror
                </fieldset>

                <div class="card-h" style="margin-top:6px"><h3>Contact details</h3></div>
                <div class="row2">
                    <div class="field">
                        <label class="lbl" for="contact_name">Name <span class="hint" style="display:inline">(optional)</span></label>
                        <input id="contact_name" type="text" name="contact_name" value="{{ old('contact_name', $user->name) }}" maxlength="120">
                        @error('contact_name')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label class="lbl" for="contact_phone">Phone <span class="hint" style="display:inline">(optional)</span></label>
                        <input id="contact_phone" type="tel" name="contact_phone" value="{{ old('contact_phone') }}" maxlength="30" placeholder="e.g. 0917 123 4567">
                        @error('contact_phone')<p class="field-err">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="field">
                    <label class="lbl" for="contact_email">Email <span class="hint" style="display:inline">(optional)</span></label>
                    <input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email', $user->email) }}" maxlength="150">
                    @error('contact_email')<p class="field-err">{{ $message }}</p>@enderror
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

                <button class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> SEND TICKET</button>
            </form>

            <aside class="card" style="align-self:start">
                <div class="card-h"><h3>What happens next</h3></div>
                <div class="pf-row"><span class="k">1. Filed</span><span class="mono">You get a ticket number</span></div>
                <div class="pf-row"><span class="k">2. Routed</span><span class="mono">It goes to the office of the category</span></div>
                <div class="pf-row"><span class="k">3. Picked up</span><span class="mono">Staff of that office take it</span></div>
                <p class="hint">You can follow every step on the ticket page.</p>
            </aside>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ticketForm', (cfg) => ({
                priority: cfg.priority,
                department: cfg.department,
                allCategories: [],
                allSubs: [],
                hasSubs: false,

                init() {
                    this.$nextTick(() => {
                        this.allCategories = Array.from(this.$refs.category.querySelectorAll('option[data-department]'));
                        this.allSubs = Array.from(this.$refs.subcategory.querySelectorAll('option[data-category]'));
                        this.filterCategories();
                    });
                    this.$watch('department', () => this.filterCategories());
                },

                // Show only the categories of the chosen department (none until one is chosen).
                filterCategories() {
                    const select = this.$refs.category;
                    const current = select.value;
                    this.allCategories.forEach(o => o.remove());
                    const keep = this.allCategories.filter(o => this.department !== '' && o.dataset.department === this.department);
                    keep.forEach(o => select.appendChild(o));
                    select.value = keep.some(o => o.value === current) ? current : '';
                    this.filterSubcategories();
                },

                // Show only the subcategories of the chosen category; hide the whole field when there are none.
                filterSubcategories() {
                    const select = this.$refs.subcategory;
                    const category = this.$refs.category.value;
                    const current = select.value;
                    this.allSubs.forEach(o => o.remove());
                    const keep = this.allSubs.filter(o => o.dataset.category === category);
                    keep.forEach(o => select.appendChild(o));
                    select.value = keep.some(o => o.value === current) ? current : '';
                    this.hasSubs = keep.length > 0;
                },
            }));
        });
    </script>
</x-app-layout>
