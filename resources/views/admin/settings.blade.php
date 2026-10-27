<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">System settings</h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-3xl mx-auto space-y-4 px-3 sm:px-6 lg:px-8">

            @if (session('status'))
                <div class="rounded-xl bg-green-100 p-4 font-semibold text-green-800" role="status">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5 rounded-xl bg-white p-5 shadow-sm">
                @csrf
                @method('PUT')

                <div>
                    <h3 class="text-lg font-bold text-gray-900">Target completion time (SLA)</h3>
                    <p class="text-sm text-gray-600">
                        Every ticket gets a target date = submission time + these hours (calendar hours, not working hours).
                        A pending ticket past its target date is shown as <strong>Overdue</strong>.
                    </p>
                </div>

                <label class="block text-sm font-semibold text-gray-700">Routine ticket (hours)
                    <input type="number" name="sla_hours_routine" min="1" max="8760" required
                           value="{{ old('sla_hours_routine', $values['sla_hours_routine']) }}"
                           class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('sla_hours_routine')" class="mt-1" />
                </label>

                <label class="block text-sm font-semibold text-gray-700">Emergency ticket (hours)
                    <input type="number" name="sla_hours_emergency" min="1" max="8760" required
                           value="{{ old('sla_hours_emergency', $values['sla_hours_emergency']) }}"
                           class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('sla_hours_emergency')" class="mt-1" />
                </label>

                <label class="block text-sm font-semibold text-gray-700">Reopen window (days after closing; 0 = reopening not allowed)
                    <input type="number" name="reopen_window_days" min="0" max="365" required
                           value="{{ old('reopen_window_days', $values['reopen_window_days']) }}"
                           class="mt-1 min-h-[44px] w-full rounded-lg border-gray-300">
                    <x-input-error :messages="$errors->get('reopen_window_days')" class="mt-1" />
                    <span class="mt-1 block text-xs font-normal text-gray-500">Used by the reopen feature (next merge phase).</span>
                </label>

                <label class="flex min-h-[44px] items-center gap-2 text-sm font-semibold text-gray-700">
                    <input type="checkbox" name="recalculate_open" value="1" class="h-5 w-5 rounded border-gray-300">
                    Also re-date the tickets that are still pending (otherwise only new tickets use the new hours)
                </label>

                <button class="min-h-[44px] rounded-lg bg-blue-600 px-5 font-bold text-white hover:bg-blue-700">Save settings</button>
            </form>
        </div>
    </div>
</x-app-layout>
