@php
    $fmt = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-base';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Approvals</h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-5xl mx-auto space-y-4 px-3 sm:px-6 lg:px-8">

            @if (session('status'))
                <div class="rounded-xl bg-green-100 p-4 font-semibold text-green-800">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-xl bg-red-100 p-4 font-semibold text-red-800" role="alert">{{ session('error') }}</div>
            @endif

            @foreach (['approve', 'disapprove'] as $bag)
                @foreach ($errors->getBag($bag)->all() as $message)
                    <div class="rounded-xl bg-red-100 p-4 font-semibold text-red-800" role="alert">{{ $message }}</div>
                @endforeach
            @endforeach

            <p class="text-sm text-gray-600">
                Tickets waiting for your authorization. Emergencies are first, then the oldest request.
                The app records who authorized and when; it does not replace the official signatures.
                To attach a signed file, open the ticket.
            </p>

            @forelse ($tickets as $ticket)
                <article class="overflow-hidden rounded-xl bg-white shadow-sm {{ $ticket->is_emergency ? 'ring-2 ring-red-500' : '' }}" id="ticket-{{ $ticket->id }}">
                    <div class="space-y-1 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('tickets.show', $ticket) }}" class="font-extrabold text-blue-700 hover:underline">{{ $ticket->ticket_no }}</a>
                            <x-status-badge :status="$ticket->status" />
                            <x-ticket-badges :ticket="$ticket" />
                        </div>
                        <div class="text-lg font-bold text-gray-900 break-words">📍 {{ $ticket->location }}</div>
                        <div class="whitespace-pre-line break-words text-gray-800">{{ $ticket->description }}</div>
                        @if ($ticket->is_emergency)
                            <div class="break-words text-sm font-semibold text-red-800">Emergency: {{ $ticket->emergency_reason }}</div>
                        @endif
                        <div class="text-sm text-gray-500">
                            {{ $ticket->requester->name }} · {{ $ticket->department?->name }} · {{ $ticket->category?->name }} · submitted {{ $fmt($ticket->submitted_at) }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 border-t border-gray-100 bg-gray-50 p-4 md:grid-cols-2">
                        <form method="POST" action="{{ route('tickets.approve', $ticket) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="return" value="approvals">
                            <label for="remarks-{{ $ticket->id }}" class="block text-sm font-semibold text-gray-700">Remarks (optional)</label>
                            <textarea id="remarks-{{ $ticket->id }}" name="remarks" rows="2" class="{{ $input }}"></textarea>
                            <button class="min-h-[48px] w-full rounded-xl bg-green-600 px-6 py-3 text-base font-bold text-white hover:bg-green-700">Approve</button>
                        </form>

                        <form method="POST" action="{{ route('tickets.disapprove', $ticket) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="return" value="approvals">
                            <label for="reason-{{ $ticket->id }}" class="block text-sm font-semibold text-gray-700">Reason to disapprove (required)</label>
                            <textarea id="reason-{{ $ticket->id }}" name="reason" rows="2" required class="{{ $input }}"></textarea>
                            <button class="min-h-[48px] w-full rounded-xl bg-red-600 px-6 py-3 text-base font-bold text-white hover:bg-red-700">Disapprove</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-xl bg-white p-8 text-center text-gray-500 shadow-sm">Nothing is waiting for your authorization. 🎉</div>
            @endforelse

            {{ $tickets->links() }}
        </div>
    </div>
</x-app-layout>
