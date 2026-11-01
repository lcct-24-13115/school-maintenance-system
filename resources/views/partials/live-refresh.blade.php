{{--
    Phase 15: auto-refresh. Used by the dashboards, the ticket lists and the ticket page.
    Every few seconds it asks /live for a short signature; when it differs from the one this page was drawn with,
    the page reloads. It NEVER reloads while someone is typing or has anything unsent in a form (it shows a
    "tap to refresh" bar instead). Pass $liveTicket (a ticket) for the ticket page. LIVE_REFRESH_SECONDS=0 turns it off.
--}}
@auth
    @php
        $liveEvery = (int) config('school.live_refresh_seconds', 8);
        $liveId = isset($liveTicket) ? (int) $liveTicket->id : 0;
        $liveSig = $liveEvery > 0 ? \App\Services\LiveSignature::for(auth()->user(), $liveId) : '';
    @endphp

    @if ($liveEvery > 0 && $liveSig !== '')
        <script id="lcct-live"
                data-sig="{{ $liveSig }}"
                data-url="{{ route('live', $liveId ? ['t' => $liveId] : []) }}"
                data-every="{{ $liveEvery * 1000 }}">
            (function () {
                var el = document.getElementById('lcct-live');
                var sig = el.dataset.sig, url = el.dataset.url, every = parseInt(el.dataset.every, 10) || 8000;
                var busy = false, bar = null, stopped = false;

                // True while the person is typing, or has something in a form that was not sent yet.
                function editing() {
                    var a = document.activeElement;
                    if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && !/^(checkbox|radio|button|submit|reset)$/.test(a.type)) return true;

                    var els = document.querySelectorAll('form input, form textarea, form select');
                    for (var i = 0; i < els.length; i++) {
                        var e = els[i];
                        if (e.type === 'hidden' || e.type === 'submit' || e.type === 'button') continue;
                        if (e.type === 'file') { if (e.files && e.files.length) return true; continue; }
                        if (e.type === 'checkbox' || e.type === 'radio') { if (e.checked !== e.defaultChecked) return true; continue; }
                        if (e.tagName === 'SELECT') {
                            for (var j = 0; j < e.options.length; j++) if (e.options[j].selected !== e.options[j].defaultSelected) return true;
                            continue;
                        }
                        if (e.value !== e.defaultValue) return true;
                    }
                    return !!document.querySelector('dialog[open], [aria-modal="true"]');
                }

                function notice() {
                    if (bar) return;
                    bar = document.createElement('div');
                    bar.setAttribute('role', 'status');
                    bar.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:9999;background:#1B3355;color:#fff;padding:10px 16px;border-radius:999px;font:600 13px/1.2 sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer';
                    bar.textContent = 'This page has new updates. Tap to refresh.';
                    bar.addEventListener('click', function () { location.reload(); });
                    document.body.appendChild(bar);
                }

                function tick() {
                    if (busy || stopped || document.hidden) return;
                    busy = true;
                    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                        .then(function (r) {
                            if (r.status === 401 || r.status === 419) stopped = true;   // signed out: stop asking
                            return r.ok ? r.json() : null;
                        })
                        .then(function (j) {
                            busy = false;
                            if (!j || !j.sig || j.sig === sig) return;
                            if (editing()) notice(); else location.reload();
                        })
                        .catch(function () { busy = false; });
                }

                setInterval(tick, every);
                document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
            })();
        </script>
    @endif
@endauth
