<x-app-layout>
    <x-slot name="header">Maintenance Tasks</x-slot>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
    @endif
    @error('board')
        <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>
    @enderror

    <div id="board">
        @include('maintenance._cards')
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
