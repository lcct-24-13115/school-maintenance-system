<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $title }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
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
