<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LCCT - Maintenance Ticketing System</title>

    {{-- Tailwind via CDN so the custom LCCT colors work without touching tailwind.config.js --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        lcct: {
                            blue: '#0d3b66',
                            dark: '#08233e',
                            light: '#1e40af',
                            gold: '#f59e0b',
                            goldDark: '#d97706',
                            goldLight: '#fef3c7',
                            bg: '#f0f4f8',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        serif: ['Cinzel', 'serif']
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #08233e;
            background-image: radial-gradient(circle at 15% 15%, rgba(30, 64, 175, 0.35) 0%, transparent 40%),
                              radial-gradient(circle at 85% 85%, rgba(245, 158, 11, 0.2) 0%, transparent 40%);
            min-height: 100vh;
        }
        .glass-panel { background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(16px); }
        .lcct-seal-bg { background: linear-gradient(135deg, #0d3b66 0%, #051628 100%); }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col justify-between">

    <header class="bg-lcct-dark border-b border-lcct-gold/30 text-white sticky top-0 z-50 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/lcct-logo.png') }}" alt="LCCT seal" class="w-11 h-11 flex-shrink-0 drop-shadow-md">
                <div>
                    <h1 class="font-serif text-sm sm:text-base font-bold tracking-wide text-amber-400 leading-tight">LA CONSOLACION COLLEGE TANAUAN</h1>
                    <p class="text-xs text-slate-300 font-medium">Maintenance Ticketing System</p>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow flex items-center justify-center p-4 sm:p-6 my-auto">
        <div class="w-full max-w-4xl glass-panel rounded-3xl shadow-2xl overflow-hidden border border-white/40 grid grid-cols-1 md:grid-cols-12 my-6">

            {{-- Left: crest --}}
            <div class="md:col-span-5 lcct-seal-bg p-8 text-white flex flex-col justify-between items-center text-center relative overflow-hidden">
                <div class="absolute inset-0 opacity-10 bg-[radial-gradient(#fff_1px,transparent_1px)] [background-size:16px_16px]"></div>

                <div class="relative z-10 my-auto py-6">
                    <img src="{{ asset('images/lcct-logo.png') }}" alt="La Consolacion College Tanauan seal" class="w-44 h-44 mx-auto mb-6 drop-shadow-[0_10px_15px_rgba(0,0,0,0.5)]">

                    <h2 class="font-serif text-2xl font-bold tracking-wide text-amber-400">Maintenance Ticketing System</h2>
                    <p class="text-xs text-slate-300 mt-2 max-w-xs mx-auto leading-relaxed">
                        La Consolacion College Tanauan
                    </p>
                </div>

                <div class="relative z-10 text-[11px] text-slate-400 border-t border-slate-700/60 pt-4 w-full">
                    Founded 1948 • Tanauan City, Batangas
                </div>
            </div>

            {{-- Right: login form --}}
            <div class="md:col-span-7 p-8 lg:p-10 flex flex-col justify-between">
                <div>
                    <div class="mb-6">
                        <h3 class="text-2xl font-bold text-slate-900">Account Login</h3>
                        <p class="text-xs text-slate-500 mt-1">Log in with your institutional account. Your role decides your dashboard.</p>
                    </div>

                    @if (session('status'))
                        <div class="mb-4 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-4 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                            <i class="fa-solid fa-circle-exclamation mr-1"></i> {{ $errors->first() }}
                        </div>
                    @endif

                    @env('local')
                        {{-- Dev only: pick a role to auto-fill its test account. Hidden outside the local environment. --}}
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-6 bg-slate-100 p-1.5 rounded-xl border border-slate-200">
                            @php
                                $demoRoles = [
                                    ['requester', 'Requester', 'fa-user-pen', 'requester@school.test'],
                                    ['maintenance', 'Maintenance', 'fa-screwdriver-wrench', 'maintenance@school.test'],
                                    ['finance', 'Finance', 'fa-coins', 'finance@school.test'],
                                    ['custodian', 'Custodian', 'fa-boxes-stacked', 'custodian@school.test'],
                                    ['president', 'President', 'fa-user-shield', 'president@school.test'],
                                ];
                            @endphp
                            @foreach ($demoRoles as $i => [$key, $label, $icon, $email])
                                <button type="button" data-role="{{ $key }}" data-email="{{ $email }}"
                                    class="role-btn py-2 px-1 text-xs rounded-lg transition text-center {{ $i === 0 ? 'bg-white text-lcct-blue font-semibold shadow-sm border border-slate-200' : 'text-slate-600 font-medium hover:text-slate-900' }}">
                                    <i class="fa-solid {{ $icon }} block mb-1 text-sm"></i> {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    @endenv

                    <form method="POST" action="{{ route('login') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label for="email" class="block text-xs font-semibold text-slate-700 mb-1">Institutional Email</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                    <i class="fa-solid fa-envelope text-xs"></i>
                                </span>
                                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                                    placeholder="name@lcc.edu.ph"
                                    class="w-full pl-9 pr-3 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl focus:ring-2 focus:ring-lcct-blue focus:border-lcct-blue outline-none transition">
                            </div>
                        </div>

                        <div>
                            <label for="password" class="block text-xs font-semibold text-slate-700 mb-1">Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                    <i class="fa-solid fa-lock text-xs"></i>
                                </span>
                                <input id="password" type="password" name="password" required autocomplete="current-password"
                                    placeholder="••••••••"
                                    class="w-full pl-9 pr-10 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl focus:ring-2 focus:ring-lcct-blue focus:border-lcct-blue outline-none transition">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                                    <i id="eyeIcon" class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs pt-1">
                            <label class="flex items-center gap-2 cursor-pointer text-slate-600">
                                <input type="checkbox" name="remember" class="rounded border-slate-300 text-lcct-blue focus:ring-lcct-blue">
                                Remember account
                            </label>
                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-lcct-light hover:underline font-semibold">Forgot password?</a>
                            @endif
                        </div>

                        <button type="submit" class="w-full py-3 bg-lcct-blue hover:bg-lcct-dark text-white rounded-xl font-semibold text-sm shadow-lg shadow-blue-900/20 transition flex items-center justify-center gap-2 mt-2">
                            <span>Log In to System</span>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </button>
                    </form>
                </div>

                <div class="mt-6 pt-5 border-t border-slate-200 text-xs text-slate-500">
                    @if (Route::has('register'))
                        No account yet?
                        <a href="{{ route('register') }}" class="text-lcct-light hover:underline font-semibold">Register as Requester</a>
                    @endif
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-lcct-dark/80 text-slate-400 text-xs py-3 border-t border-slate-800 text-center">
        <p>© {{ date('Y') }} La Consolacion College Tanauan • Maintenance Ticketing System</p>
    </footer>

    <script>
        // Show / hide password
        document.getElementById('togglePassword').addEventListener('click', function () {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        });

        // Local-only role tabs: fill the test account of the chosen role
        document.querySelectorAll('.role-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.role-btn').forEach(function (b) {
                    b.classList.remove('bg-white', 'text-lcct-blue', 'font-semibold', 'shadow-sm', 'border', 'border-slate-200');
                    b.classList.add('text-slate-600', 'font-medium');
                });
                btn.classList.add('bg-white', 'text-lcct-blue', 'font-semibold', 'shadow-sm', 'border', 'border-slate-200');
                btn.classList.remove('text-slate-600', 'font-medium');
                document.getElementById('email').value = btn.dataset.email;
                document.getElementById('password').value = 'password';
            });
        });
    </script>
</body>
</html>
