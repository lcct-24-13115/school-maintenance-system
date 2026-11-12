# PWA (Progressive Web App): LCCT Maintenance

The system can be installed on phones, tablets and computers like a normal app: own icon, own window, no browser bar.
It is a plain-file PWA: **no migration, no `npm install`, no `npm run build`** for the PWA parts. Everything sits in `public/`.

## Files

| File | What it does |
|---|---|
| `public/manifest.webmanifest` | App name ("LCCT Maintenance Ticketing System" / short "LCCT Maintenance"), colours (`#1B3355` theme, `#F5F9FD` background), `display: standalone`, start page `./dashboard`, four icons, two shortcuts (**New repair request**, **Help & FAQ**). Paths are relative, so it works at the site root or in a sub-folder. |
| `public/sw.js` | The service worker. **Must stay in `public/`** so its scope covers the whole app. |
| `public/offline.html` | Stand-alone "You're offline" page (logo inside, no outside files). |
| `public/js/pwa.js` | Registers `sw.js` and shows the **Install app** button when the browser offers one. |
| `public/images/pwa/` | `icon-192.png`, `icon-512.png`, `maskable-192.png`, `maskable-512.png`. |
| `public/images/apple-touch-icon.png`, `favicon-32.png` | iPhone home-screen icon and favicon. |
| `resources/views/partials/pwa.blade.php` | `<link rel="manifest">`, theme / Apple tags, the `pwa-sw` and `pwa-scope` meta tags and the script tag. |
| `resources/views/partials/ui-assets.blade.php` | Includes `partials.pwa`. It is loaded by both the guest and the app layout, so every page is covered. |
| `tests/Feature/PwaTest.php` | Checks that the files exist, the manifest is valid and every icon exists, the offline page stands alone, and the worker never stores pages. |

## Requirements

* **HTTPS**, or `localhost` / `127.0.0.1` (a browser rule, not ours).
  * Local: use `php artisan serve` (`http://127.0.0.1:8000`) or turn on SSL in Laragon. `http://school-maintenance-system.test` **without** SSL will not register the worker.
  * Production: serve over HTTPS with `public/` as the web root, and set `APP_URL` to that address.
* A browser that supports service workers (current Chrome, Edge, Safari, Firefox).

## How people install it

| Device | Steps |
|---|---|
| Android (Chrome, Edge) | Tap **Install app** (bottom-right of the page) or browser menu -> *Install app*. The small **x** hides the button; the choice is remembered in the browser (`localStorage` key `lcct-pwa-dismissed`). |
| Desktop (Chrome, Edge) | Install icon in the address bar, or the same button. |
| iPhone / iPad (Safari) | Share -> **Add to Home Screen**. iOS has no install button; that is normal. |

The installed app opens at `/dashboard`, which sends each person to their own role dashboard. Long-press the icon (Android) for the two shortcuts.

## What is stored on the device (and what is not)

| Request | Treatment |
|---|---|
| Page loads (every navigation: login, dashboard, tickets, admin...) | **Always from the network.** If there is no internet, `offline.html` is shown. A signed-in page is never stored (privacy on shared phones, fresh CSRF tokens). |
| `css/`, `js/`, `images/`, `build/` (same origin) | Stale-while-revalidate: opens fast, refreshes in the background. |
| Google Fonts, Font Awesome (`fonts.googleapis.com`, `fonts.gstatic.com`, `cdnjs.cloudflare.com`) | Cache-first. |
| Pre-cached at install | `offline.html`, `css/style.css`, `css/phase6.css`, `images/lcct-logo.png`, `images/pwa/icon-192.png` |
| Attachments, avatars, QR images, `/live`, notifications poll, every POST | Straight to the network, never stored. |

Result: data is always fresh and no private ticket, file or photo is saved on a phone. **Offline you only get the offline page**; the app does not work offline.

## Web Push

Not switched on. `sw.js` already has the `push` and `notificationclick` handlers, but sending a push needs VAPID keys, a subscription table and server code. In-app alerts (bell, pop-up, chime) and e-mails are what exist today.

## On every new release

Change `CACHE_VERSION` in `public/sw.js` (for example `'v1-pwa1'` -> `'v1-pwa2'`). Old caches (`lcct-static-*`, `lcct-fonts-*`) are deleted when the new worker activates. Registration uses `updateViaCache: 'none'`, so the browser checks `sw.js` itself each time.
After `npm run build` the hashed files in `build/` change names on their own; you only need the version bump when a **non-hashed** file (`css/*.css`, `js/*.js`, images) changed.

## Testing

* **Automatic:** `php artisan test --filter=PwaTest`.
* **Chrome DevTools -> Application:**
  * *Manifest*: no errors, four icons visible.
  * *Service Workers*: `sw.js` status *activated and running*.
  * *Cache Storage*: only `lcct-static-*` and `lcct-fonts-*`; no HTML pages, attachments or avatars.
  * Network -> **Offline**, reload: the "You're offline" page appears.
* **Lighthouse** -> Progressive Web App (if the category is missing in your Chrome version, use the Application tab).
* **Real phone:** install it, turn on airplane mode, open it: offline page; turn it off: dashboard loads.

## Troubleshooting

| Problem | Likely cause / fix |
|---|---|
| No **Install app** button | Not on HTTPS / localhost; already installed; you pressed **x** earlier (clear the site's local storage); the browser does not offer install on this page yet. |
| Old design after an update | `CACHE_VERSION` not changed. Hard refresh (Ctrl+F5), or DevTools -> Application -> *Clear site data*. |
| `Failed to register a ServiceWorker` | The site is not HTTPS, or `sw.js` is not reachable at `/sw.js` (web root must be `public/`). |
| Works at `localhost`, not at `.test` | Laragon `.test` address without SSL (see Requirements). |
| Installed app shows an old page | Pages are never cached; check the server, not the worker. |

## Rollback

Delete the "new" files (`manifest.webmanifest`, `sw.js`, `offline.html`, `js/pwa.js`, `images/pwa/`, `partials/pwa.blade.php`, `PwaTest.php`) and remove `@include('partials.pwa')` from `partials/ui-assets.blade.php`.
To remove the worker from devices that already have it, first replace the content of `public/sw.js` with:

```js
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.registration.unregister()));
```

and leave it online until people have opened the app once.
