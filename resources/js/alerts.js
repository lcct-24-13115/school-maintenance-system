import { startEcho } from './echo';

const root = document.getElementById('lcct-alerts');

if (root) {
    const pollUrl = root.dataset.pollUrl;
    const interval = Number(root.dataset.interval || 5) * 1000;
    const userId = root.dataset.userId;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const toasts = document.getElementById('lcct-toasts');
    const badge = document.getElementById('lcct-badge');
    const banner = document.getElementById('lcct-sound-banner');

    const seen = new Set();
    let since = null;
    let ctx = null;

    // ---- Sound: browsers only allow audio after the first tap/click on the page ----
    function unlock() {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        ctx = ctx || new AC();
        ctx.resume().then(() => banner && banner.classList.add('hidden'));
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach((e) => document.addEventListener(e, unlock, { passive: true }));

    function beep(freq, start, length) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'square';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, ctx.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(0.6, ctx.currentTime + start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + length);
        osc.connect(gain).connect(ctx.destination);
        osc.start(ctx.currentTime + start);
        osc.stop(ctx.currentTime + start + length + 0.05);
    }

    function chime(urgent) {
        if (!ctx || ctx.state !== 'running') return;
        // Normal: ding-dong x2.  Emergency: loud alternating siren x6.
        const pattern = urgent ? [...Array(6).keys()].map((i) => [i % 2 ? 1175 : 880, i * 0.28, 0.25]) : [[880, 0, 0.25], [1175, 0.3, 0.4], [880, 0.9, 0.25], [1175, 1.2, 0.4]];
        pattern.forEach(([f, s, l]) => beep(f, s, l));
        if (navigator.vibrate) navigator.vibrate(urgent ? [400, 150, 400, 150, 400] : [300, 150, 300]);
    }

    // ---- Pop-up card ----
    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function toast(n) {
        const urgent = !!n.urgent;
        const el = document.createElement('div');
        el.className = 'pointer-events-auto w-full rounded-xl border-l-8 bg-white p-4 shadow-2xl ring-1 ring-black/10 ' + (urgent ? 'border-red-600 animate-pulse' : 'border-blue-600');
        el.innerHTML = `
            <div class="text-xs font-bold uppercase tracking-wide ${urgent ? 'text-red-600' : 'text-blue-600'}">${esc(n.form_label)} &middot; ${esc(n.reference_no)}</div>
            <div class="mt-1 text-lg font-bold text-gray-900">${esc(n.title)}</div>
            <div class="mt-1 text-sm text-gray-600">${esc(n.requester_name)}${n.department ? ' &middot; ' + esc(n.department) : ''}</div>
            <div class="text-sm text-gray-600">${esc(n.message)}</div>
            <div class="mt-3 flex gap-2">
                <a href="${esc(n.url)}" class="flex-1 rounded-lg bg-blue-600 px-4 py-3 text-center text-base font-bold text-white">VIEW</a>
                <button type="button" class="rounded-lg bg-gray-200 px-4 py-3 text-base font-bold text-gray-700">OK</button>
            </div>`;
        const close = () => {
            el.remove();
            fetch(`/notifications/${n.id}/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
        };
        el.querySelector('button').addEventListener('click', close);
        toasts.prepend(el);
        if (!urgent) setTimeout(() => el.isConnected && el.remove(), 15000); // emergencies stay until someone taps OK
    }

    function handle(n) {
        if (!n.id || seen.has(n.id)) return;   // same alert can arrive by WebSocket AND polling
        seen.add(n.id);
        chime(!!n.urgent);
        toast(n);
        if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
            new Notification(n.title, { body: `${n.requester_name}: ${n.message}` });
        }
        window.dispatchEvent(new CustomEvent('lcct:alert', { detail: n }));
    }

    // ---- Polling (works everywhere, no WebSocket server needed) ----
    async function poll() {
        try {
            const url = pollUrl + (since ? `?since=${encodeURIComponent(since)}` : '');
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            since = data.server_time;
            if (badge) {
                badge.textContent = data.unread_count;
                badge.classList.toggle('hidden', data.unread_count === 0);
            }
            data.items.forEach(handle);
        } catch (e) { /* offline: try again next time */ }
    }

    poll();
    setInterval(poll, interval);
    startEcho(userId, (n) => handle({ ...n, id: n.id })).catch(() => {});
}
