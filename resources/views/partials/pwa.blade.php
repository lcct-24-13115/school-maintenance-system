{{-- PWA: manifest, theme and icons for "Add to Home screen" + the script that registers the service worker.
     Included from partials/ui-assets.blade.php, so every page of both layouts (guest + app) gets it.
     The files are plain files in /public: manifest.webmanifest, sw.js, offline.html, js/pwa.js, images/pwa/*. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="application-name" content="LCCT Maintenance">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="LCCT Maintenance">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="pwa-sw" content="{{ asset('sw.js') }}">
<meta name="pwa-scope" content="{{ rtrim(asset('/'), '/') }}/">
<script src="{{ asset('js/pwa.js') }}?v={{ @filemtime(public_path('js/pwa.js')) }}" defer></script>
