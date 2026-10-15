<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Notifications</h2>
            <form method="POST" action="{{ route('notifications.readAll') }}">
                @csrf
                <button class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">Mark all as read</button>
            </form>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-3">
            @forelse ($notifications as $n)
                @php($d = $n->data)
                <div class="rounded-lg bg-white p-4 shadow-sm border-l-8 {{ ($d['urgent'] ?? false) ? 'border-red-600' : ($n->read_at ? 'border-gray-300' : 'border-blue-600') }}">
                    <div class="text-xs font-bold uppercase text-gray-500">{{ $d['form_label'] ?? 'Notice' }} · {{ $d['reference_no'] ?? '' }}</div>
                    <div class="mt-1 font-semibold text-gray-900">{{ $d['title'] ?? '' }}</div>
                    <div class="text-sm text-gray-600">{{ $d['requester_name'] ?? '' }}@if (!empty($d['department'])) · {{ $d['department'] }}@endif</div>
                    <div class="text-sm text-gray-600">{{ $d['message'] ?? '' }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ $n->created_at->diffForHumans() }}</div>
                </div>
            @empty
                <p class="text-center text-gray-500">No notifications yet.</p>
            @endforelse

            {{ $notifications->links() }}
        </div>
    </div>
</x-app-layout>
