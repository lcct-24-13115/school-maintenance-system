<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Maintenance Tasks</h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-lg bg-green-100 p-4 text-lg font-bold text-green-800">{{ session('status') }}</div>
            @endif
            @error('board')
                <div class="mb-4 rounded-lg bg-red-100 p-4 text-lg font-bold text-red-800">{{ $message }}</div>
            @enderror

            <div id="board">
                @include('maintenance._cards')
            </div>
        </div>
    </div>

    <script>
        // Refresh the cards when an alert arrives, and every 30 seconds as a safety net.
        const refreshBoard = async () => {
            try {
                const res = await fetch(@json(route('maintenance.board.cards')), { credentials: 'same-origin' });
                if (res.ok) document.getElementById('board').innerHTML = await res.text();
            } catch (e) {}
        };
        window.addEventListener('lcct:alert', refreshBoard);
        setInterval(refreshBoard, 30000);
    </script>
</x-app-layout>
