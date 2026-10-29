<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'LCCT') }} - Maintenance Ticketing System</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;0,9..144,700;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    {{-- Vite (Alpine, live alerts, and Tailwind for pages that are not redesigned yet) comes FIRST,
         then the LCCT design system, so the LCCT styles win any conflict. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ @filemtime(public_path('css/style.css')) }}">
</head>
<body>
    <div x-data="{ sb: false }" @keydown.escape.window="sb = false">

        @include('layouts.navigation')

        {{-- dark backdrop behind the sidebar on phones --}}
        <div class="sb-scrim" x-show="sb" x-cloak x-transition.opacity @click="sb = false"></div>

        <div class="main">
            <header class="topbar">
                <button type="button" class="menu-btn" @click="sb = true" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div class="topbar-head">
                    @isset($header)
                        {{ $header }}
                    @endisset
                </div>

                {{-- Notification bell. #lcct-badge is updated live by resources/js/alerts.js --}}
                <div class="bell">
                    <a href="{{ route('notifications.index') }}" class="bell-btn" title="Notifications" aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <span id="lcct-badge" class="bell-n hidden">0</span>
                    </a>
                </div>

                {{-- Profile menu --}}
                <div class="tb-user" x-data="{ open: false }" :class="{ 'open': open }" @click.outside="open = false">
                    <button type="button" class="who" @click="open = !open" aria-haspopup="true">
                        <x-avatar :user="Auth::user()" />
                        <span class="nm">{{ Auth::user()->name }}<small>{{ Auth::user()->role?->name }}</small></span>
                        <i class="fa-solid fa-chevron-down chev"></i>
                    </button>
                    <div class="tb-menu" x-cloak>
                        <div class="tb-head" style="display:flex;align-items:center;gap:10px">
                            <x-avatar :user="Auth::user()" :size="44" />
                            <span><b>{{ Auth::user()->name }}</b><small>{{ Auth::user()->email }}</small></span>
                        </div>
                        <div class="tb-sep"></div>
                        <a href="{{ route('profile.edit') }}"><i class="fa-solid fa-user-gear"></i> Profile</a>
                        <a href="{{ route('notifications.index') }}"><i class="fa-solid fa-bell"></i> Notifications</a>
                        <div class="tb-sep"></div>
                        <a href="#" class="tb-out" onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                            <i class="fa-solid fa-right-from-bracket"></i> Log Out
                        </a>
                    </div>
                </div>
            </header>

            <main class="content">
                {{ $slot }}
            </main>
        </div>
    </div>

    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">@csrf</form>

    @include('partials.alerts')
    @stack('scripts')
</body>
</html>
