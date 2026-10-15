// Optional instant delivery over WebSockets (Laravel Reverb). Does nothing unless VITE_REVERB_APP_KEY is set.
export async function startEcho(userId, onNotification) {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key || !userId) return;

    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    window.Pusher = Pusher;
    const secure = (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo.private(`App.Models.User.${userId}`).notification(onNotification);
}
