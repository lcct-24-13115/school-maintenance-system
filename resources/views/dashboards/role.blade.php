<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $title }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-6 rounded-lg bg-green-100 p-4 text-green-800 font-semibold">{{ session('status') }}</div>
            @endif

            @if (auth()->user()->hasRole('requester', 'department_head'))
                <a href="{{ route('repairs.create') }}" class="mb-6 block rounded-xl bg-blue-600 px-6 py-5 text-center text-xl font-bold text-white shadow hover:bg-blue-700">
                    🛠️ Request for Repairs &amp; Improvement (FO-08)
                </a>
            @endif

            <p class="mb-6 text-gray-600">
                Welcome, {{ auth()->user()->name }} ({{ auth()->user()->role->name }})
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($cards as $label => $value)
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-gray-500">{{ $label }}</div>
                        <div class="mt-2 text-3xl font-semibold text-gray-900">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
