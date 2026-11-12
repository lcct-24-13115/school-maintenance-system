/* LCCT Maintenance - Service Worker (PWA)
 *
 * What it does:
 *   - Pages (every navigation): ALWAYS from the network. If there is no internet, the offline page is shown.
 *     A signed-in page is never stored on the device (privacy on shared phones, fresh CSRF tokens).
 *   - Static files (/css, /js, /images, /build): stale-while-revalidate, so the app opens fast.
 *   - Google Fonts / Font Awesome (CDN): cache-first.
 *   - POST, uploads/attachments, avatars, QR images, live-refresh calls and anything else: straight to the network.
 *
 * Every new release: change CACHE_VERSION below so phones drop the old cache.
 * This file must stay in /public (the site root) so its scope covers the whole app.
 */
const CACHE_VERSION = 'v1-pwa2';
const STATIC_CACHE = 'lcct-static-' + CACHE_VERSION;
const FONT_CACHE = 'lcct-fonts-' + CACHE_VERSION;
const SCOPE = self.registration.scope;                       // e.g. https://site/  or  http://localhost/lcct/
const OFFLINE_URL = new URL('offline.html', SCOPE).href;

const PRECACHE = [
  OFFLINE_URL,
  new URL('css/style.css', SCOPE).href,
  new URL('css/phase6.css', SCOPE).href,
  new URL('images/lcct-logo.png', SCOPE).href,
  new URL('images/pwa/icon-192.png', SCOPE).href,
];

// Only files under these folders (inside the scope) are kept on the device.
const STATIC_PREFIXES = ['css/', 'js/', 'images/', 'build/'].map(function (p) { return new URL(p, SCOPE).pathname; });
const CDN_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com'];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(function (cache) {
        // cache.addAll would fail the whole install if one file is missing; add them one by one instead.
        return Promise.all(PRECACHE.map(function (u) { return cache.add(u).catch(function () {}); }));
      })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys
          .filter(function (k) { return k.indexOf('lcct-') === 0 && k !== STATIC_CACHE && k !== FONT_CACHE; })
          .map(function (k) { return caches.delete(k); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('message', function (event) {
  if (event.data === 'SKIP_WAITING') { self.skipWaiting(); }
  if (event.data === 'CLEAR_CACHES') {
    event.waitUntil(caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k.indexOf('lcct-') === 0; }).map(function (k) { return caches.delete(k); }));
    }));
  }
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }

  var url = new URL(req.url);

  // CDN fonts and icons: cache-first
  if (CDN_HOSTS.indexOf(url.hostname) !== -1) {
    event.respondWith(cacheFirst(req, FONT_CACHE));
    return;
  }

  if (url.origin !== self.location.origin) { return; }

  // Page navigations: network, then the offline page. Never cached.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () {
      return caches.match(OFFLINE_URL).then(function (hit) {
        return hit || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
      });
    }));
    return;
  }

  // Static files: stale-while-revalidate
  if (STATIC_PREFIXES.some(function (p) { return url.pathname.indexOf(p) === 0; })) {
    event.respondWith(staleWhileRevalidate(req, STATIC_CACHE));
  }
  // everything else (attachments, avatars, QR codes, live refresh ...) goes to the network untouched
});

function cacheFirst(req, cacheName) {
  return caches.open(cacheName).then(function (cache) {
    return cache.match(req).then(function (hit) {
      if (hit) { return hit; }
      return fetch(req).then(function (res) {
        if (res && (res.ok || res.type === 'opaque')) { cache.put(req, res.clone()); }
        return res;
      });
    });
  });
}

function staleWhileRevalidate(req, cacheName) {
  return caches.open(cacheName).then(function (cache) {
    return cache.match(req).then(function (hit) {
      var network = fetch(req).then(function (res) {
        if (res && res.ok) { cache.put(req, res.clone()); }
        return res;
      }).catch(function () { return hit; });
      return hit || network;
    });
  });
}

/* Web Push is not switched on in this release (it needs VAPID keys and server code).
 * The handlers are here so it can be added later without changing the worker. */
self.addEventListener('push', function (event) {
  if (!event.data) { return; }
  var data = {};
  try { data = event.data.json(); } catch (e) { data = { title: 'LCCT Maintenance', body: event.data.text() }; }
  event.waitUntil(self.registration.showNotification(data.title || 'LCCT Maintenance', {
    body: data.body || '',
    icon: new URL('images/pwa/icon-192.png', SCOPE).href,
    data: { url: data.url || SCOPE }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = (event.notification.data && event.notification.data.url) || SCOPE;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if (list[i].url === target && 'focus' in list[i]) { return list[i].focus(); } }
    return self.clients.openWindow(target);
  }));
});
