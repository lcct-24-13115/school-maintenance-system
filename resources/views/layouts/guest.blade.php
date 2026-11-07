<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    @include('partials.ui-assets')
    <title>{{ config('app.name', 'LCCT') }} - Maintenance Ticketing System</title>
</head>
<body class="auth-body">
    <div class="stage">

        {{-- Phones: campus photo on top with brand (desktop hides this) --}}
        <div class="hero-m">
            <img src="{{ asset('images/lcct-logo.png') }}" alt="LCCT seal">
            <div>
                <div class="brand">LCCT <span>Maintenance</span></div>
                <small>La Consolacion College Tanauan</small>
            </div>
        </div>

        <main class="auth-card {{ $attributes->has('wide') ? 'wide' : '' }}">
            <div class="auth-logo"><img src="{{ asset('images/lcct-logo.png') }}" alt="La Consolacion College Tanauan seal"></div>
            <div class="auth-brand">
                <div class="brand">LCCT <span>Maintenance</span></div>
                <small>La Consolacion College Tanauan</small>
            </div>

            {{ $slot }}
        </main>

        @if (request()->routeIs('login', 'register', 'password.request', 'password.reset'))
            <link rel="stylesheet" href="{{ asset('css/help.css') }}?v={{ @filemtime(public_path('css/help.css')) }}">
            <a class="auth-help-link" href="{{ route('help.public') }}"><i class="fa-regular fa-circle-question" aria-hidden="true"></i> Help &amp; FAQ</a>
        @endif

        <div class="auth-credit">
            <img src="{{ asset('images/intel-logo.png') }}" alt="INTEL">
            <span>Powered by <b>INTEL</b></span>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
