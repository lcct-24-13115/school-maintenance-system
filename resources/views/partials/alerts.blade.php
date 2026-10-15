@auth
    {{-- Live alerts: chime + pop-up + bell badge. Script: resources/js/alerts.js --}}
    <div id="lcct-alerts"
         data-poll-url="{{ route('notifications.poll') }}"
         data-interval="{{ config('school.poll_seconds') }}"
         data-user-id="{{ auth()->id() }}"></div>

    <div id="lcct-toasts" class="pointer-events-none fixed inset-x-0 top-2 z-50 mx-auto flex max-w-md flex-col gap-3 px-3"></div>

    <div id="lcct-sound-banner" class="fixed inset-x-0 bottom-0 z-40 bg-yellow-300 px-4 py-3 text-center text-base font-bold text-gray-900 shadow-lg">
        🔔 Tap anywhere on the screen to turn ON the alert sound
    </div>
@endauth
