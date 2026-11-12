{{-- Phase 18: shared UI extras for every page that uses the LCCT design (guest + app layouts).
     Include AFTER style.css so phase6.css can add to it. No build step: these are plain files in /public. --}}
<meta name="theme-color" content="#1B3355">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
<link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
<script src="{{ asset('js/pwtoggle.js') }}?v={{ @filemtime(public_path('js/pwtoggle.js')) }}" defer></script>
<script src="{{ asset('js/cselect.js') }}?v={{ @filemtime(public_path('js/cselect.js')) }}" defer></script>
@include('partials.pwa')
