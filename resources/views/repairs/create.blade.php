<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Request for Repairs &amp; Improvement <span class="text-gray-400">(FO-08)</span></h2>
    </x-slot>

    <div class="py-6" x-data="repairForm({
            priority: @js(old('priority', 'first_come_first_served')),
            requestType: @js((string) old('request_type', '')),
            items: @js(array_values((array) old('items', []))),
        })">
        <div class="max-w-2xl mx-auto px-4 sm:px-6">
            <p class="mb-4 rounded-lg bg-yellow-100 p-3 text-sm font-semibold text-yellow-900">
                Request must be made 5 working days in advance.
            </p>

            @error('form')<p class="mb-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror

            <form method="POST" action="{{ route('repairs.store') }}" enctype="multipart/form-data" class="space-y-5 rounded-xl bg-white p-5 shadow-sm">
                @csrf

                <div>
                    <label class="block text-base font-semibold text-gray-800">From: (Department/Office)</label>
                    <select name="department_id" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected(old('department_id', $defaultDepartment) == $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Request type <span class="font-normal text-gray-500">(optional)</span></label>
                    <select name="request_type" x-model="requestType" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                        <option value="">None (a repair or service)</option>
                        @foreach ($requestTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Choose one only if you are asking for materials, equipment or supplies. The category list below will match it.</p>
                    @error('request_type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Maintenance category</label>
                    <select name="category_id" x-ref="category" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                        <option value="">— Choose one —</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" data-type="{{ $c->type }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                {{-- Items: only when a request type is chosen. Rows sent without a type are ignored by the server. --}}
                <div x-show="requestType !== ''" x-cloak class="space-y-3 rounded-xl border border-gray-200 p-4">
                    <div class="text-base font-semibold text-gray-800">Items needed <span class="font-normal text-gray-500">(optional)</span></div>

                    <template x-for="(item, i) in items" :key="i">
                        <div class="space-y-2 rounded-lg bg-gray-50 p-3">
                            <input type="text" :name="'items[' + i + '][item_name]'" x-model="item.item_name" maxlength="255" placeholder="Item name" class="w-full rounded-lg border-gray-300 py-3 text-base">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" inputmode="numeric" min="1" step="1" :name="'items[' + i + '][quantity]'" x-model="item.quantity" placeholder="Quantity" class="w-full rounded-lg border-gray-300 py-3 text-base">
                                <input type="text" :name="'items[' + i + '][unit]'" x-model="item.unit" maxlength="50" placeholder="Unit (pc, box, set)" class="w-full rounded-lg border-gray-300 py-3 text-base">
                            </div>
                            <input type="text" :name="'items[' + i + '][specification]'" x-model="item.specification" maxlength="1000" placeholder="Specification (size, brand, model...)" class="w-full rounded-lg border-gray-300 py-3 text-base">
                            <button type="button" @click="removeItem(i)" class="min-h-[44px] text-sm font-bold text-red-600">Remove this item</button>
                        </div>
                    </template>

                    <button type="button" @click="addItem()" x-show="items.length < {{ \App\Http\Requests\Forms\StoreRepairRequestRequest::MAX_ITEMS }}"
                            class="min-h-[48px] w-full rounded-xl border-2 border-dashed border-blue-400 px-3 py-3 text-base font-bold text-blue-700">
                        + Add an item
                    </button>

                    @foreach ($errors->get('items*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Where is it? (building / room)</label>
                    <input name="location" value="{{ old('location') }}" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base" placeholder="e.g. Grade 5 Room 204">
                    @error('location')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Job Description</label>
                    <textarea name="job_description" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-base" placeholder="What needs to be fixed or improved?">{{ old('job_description') }}</textarea>
                    @error('job_description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Reason for Request</label>
                    <textarea name="reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-base">{{ old('reason') }}</textarea>
                    @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <fieldset>
                    <legend class="text-base font-semibold text-gray-800">Priority</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="cursor-pointer rounded-xl border-2 p-4 text-center font-bold" :class="priority === 'first_come_first_served' ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-gray-200 text-gray-600'">
                            <input type="radio" name="priority" value="first_come_first_served" x-model="priority" class="sr-only">
                            First Come<br>First Served
                        </label>
                        <label class="cursor-pointer rounded-xl border-2 p-4 text-center font-bold" :class="priority === 'emergency' ? 'border-red-600 bg-red-50 text-red-700' : 'border-gray-200 text-gray-600'">
                            <input type="radio" name="priority" value="emergency" x-model="priority" class="sr-only">
                            @if ($canFlag)
                                🚨 Emergency
                            @else
                                🚨 Ask for<br>emergency review
                            @endif
                        </label>
                    </div>
                    @unless ($canFlag)
                        <p x-show="priority === 'emergency'" class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                            Your ticket will be filed as a routine request. Maintenance will see that you asked for an emergency review and your reason below.
                        </p>
                    @endunless
                    @error('priority')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>

                <div x-show="priority !== 'emergency' || {{ $emergencyBypass ? 'false' : 'true' }}">
                    <label class="block text-base font-semibold text-gray-800">Date needed</label>
                    <input type="date" name="needed_by" value="{{ old('needed_by') }}" min="{{ $earliest->toDateString() }}" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                    <p class="mt-1 text-xs text-gray-500">Earliest allowed: {{ $earliest->format('M j, Y (l)') }}</p>
                    @error('needed_by')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Photos &amp; documents <span class="font-normal text-gray-500">(optional)</span></label>
                    <x-file-picker name="files" />
                    @if ($errors->has('files') || $errors->has('files.*'))
                        <p class="mt-2 text-sm text-gray-600">Your browser does not keep chosen files after an error, so please pick them again.</p>
                    @endif
                    @error('files')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    @foreach ($errors->get('files.*') as $messages)
                        @foreach ((array) $messages as $message)
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4">
                    <input type="checkbox" name="is_confidential" value="1" @checked(old('is_confidential')) class="mt-1 h-6 w-6 rounded border-gray-300 text-blue-600">
                    <span>
                        <span class="block text-base font-semibold text-gray-800">🔒 Mark as confidential</span>
                        <span class="block text-sm text-gray-600">Only you, the School President, Maintenance Administrators and the staff member assigned to it can see this ticket and its files.</span>
                    </span>
                </label>

                <button class="w-full rounded-xl bg-green-600 py-4 text-xl font-bold text-white hover:bg-green-700">SEND REQUEST</button>
            </form>
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
