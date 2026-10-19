<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Request for Repairs &amp; Improvement <span class="text-gray-400">(FO-08)</span></h2>
    </x-slot>

    <div class="py-6" x-data="{ priority: '{{ old('priority', 'first_come_first_served') }}' }">
        <div class="max-w-2xl mx-auto px-4 sm:px-6">
            <p class="mb-4 rounded-lg bg-yellow-100 p-3 text-sm font-semibold text-yellow-900">
                Request must be made 5 working days in advance.
            </p>

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
                    <label class="block text-base font-semibold text-gray-800">Maintenance category</label>
                    <select name="category_id" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                        <option value="">— Choose one —</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
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
                            🚨 Emergency
                        </label>
                    </div>
                    @error('priority')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>

                <div x-show="priority !== 'emergency' || {{ config('school.emergency_bypasses_advance_notice') ? 'false' : 'true' }}">
                    <label class="block text-base font-semibold text-gray-800">Date needed</label>
                    <input type="date" name="needed_by" value="{{ old('needed_by') }}" min="{{ $earliest->toDateString() }}" class="mt-1 w-full rounded-lg border-gray-300 py-3 text-base">
                    <p class="mt-1 text-xs text-gray-500">Earliest allowed: {{ $earliest->format('M j, Y (l)') }}</p>
                    @error('needed_by')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-base font-semibold text-gray-800">Photo (optional)</label>
                    <input type="file" name="photo" accept="image/*" capture="environment" class="mt-1 w-full text-base">
                    @error('photo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <button class="w-full rounded-xl bg-green-600 py-4 text-xl font-bold text-white hover:bg-green-700">SEND REQUEST</button>
            </form>
        </div>
    </div>
</x-app-layout>
