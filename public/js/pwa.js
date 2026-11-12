/* PWA: registers the service worker and shows an "Install app" button when the browser offers one.
 * Paths come from <meta name="pwa-sw"> and <meta name="pwa-scope"> (partials/pwa.blade.php), so it works at the
 * site root and in a sub-folder. Needs HTTPS or localhost (browser rule). */
(function () {
  'use strict';

  var swMeta = document.querySelector('meta[name="pwa-sw"]');
  var scopeMeta = document.querySelector('meta[name="pwa-scope"]');

  if ('serviceWorker' in navigator && swMeta && scopeMeta) {
    window.addEventListener('load', function () {
      navigator.serviceWorker
        .register(swMeta.content, { scope: scopeMeta.content, updateViaCache: 'none' })
        .catch(function () { /* the app works the same without it */ });
    });
  }

  /* ---------- "Install app" button ---------- */
  var KEY = 'lcct-pwa-dismissed';
  var deferred = null;
  var btn = null;

  function dismissed() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function remember() { try { localStorage.setItem(KEY, '1'); } catch (e) { /* ignore */ } }

  function hide() { if (btn) { btn.remove(); btn = null; } }

  function show() {
    if (btn || !deferred || dismissed()) { return; }

    var style = document.createElement('style');
    style.textContent =
      '.pwa-install{position:fixed;right:16px;bottom:calc(16px + env(safe-area-inset-bottom));z-index:60;display:flex;align-items:center;gap:6px;' +
      'background:#1B3355;color:#fff;border-radius:999px;box-shadow:0 10px 24px -10px rgba(27,51,85,.6);font-weight:700;font-size:13.5px;line-height:1}' +
      '.pwa-install button{font-family:inherit;font-weight:inherit;font-size:inherit;color:inherit;background:none;border:0;cursor:pointer}' +
      '.pwa-install .go{padding:12px 6px 12px 16px}.pwa-install .x{padding:12px 14px 12px 6px;opacity:.7;font-size:16px}' +
      '.pwa-install button:hover{opacity:.85}.pwa-install button:focus-visible{outline:3px solid #5E94CF;outline-offset:2px;border-radius:999px}' +
      '@media print{.pwa-install{display:none}}';
    document.head.appendChild(style);

    btn = document.createElement('div');
    btn.className = 'pwa-install';
    btn.innerHTML = '<button type="button" class="go">Install app</button><button type="button" class="x" aria-label="Hide install button">&times;</button>';
    document.body.appendChild(btn);

    btn.querySelector('.go').addEventListener('click', function () {
      if (!deferred) { return; }
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; hide(); });
    });
    btn.querySelector('.x').addEventListener('click', function () { remember(); hide(); });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (document.body) { show(); } else { document.addEventListener('DOMContentLoaded', show); }
  });

  window.addEventListener('appinstalled', function () { deferred = null; hide(); });
})();
