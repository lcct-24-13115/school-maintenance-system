<x-guest-layout>
    <h1 class="form-title">Welcome <span>back</span></h1>
    <p class="form-sub">Log in with your institutional account. Your role decides your dashboard.</p>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $errors->first() }}</span></div>
    @endif

    @env('local')
        {{-- Dev only: pick a role to auto-fill its test account. Hidden outside the local environment. --}}
        <div class="tile-wrap">
            <span class="tile-lbl">Quick demo login (local only)</span>
            <div class="tile-grid">
                @php
                    $demoRoles = [
                        ['requester', 'Requester', 'fa-user-pen', 'requester@lcctanauan.edu.ph'],
                        ['maintenance', 'Maintenance', 'fa-screwdriver-wrench', 'maintenance@lcctanauan.edu.ph'],
                        ['finance', 'Finance', 'fa-coins', 'finance@lcctanauan.edu.ph'],
                        ['custodian', 'Custodian', 'fa-boxes-stacked', 'custodian@lcctanauan.edu.ph'],
                        ['president', 'President', 'fa-user-shield', 'president@lcctanauan.edu.ph'],
                    ];
                @endphp
                @foreach ($demoRoles as $i => [$key, $label, $icon, $email])
                    <button type="button" data-role="{{ $key }}" data-email="{{ $email }}"
                        class="tile-opt role-btn {{ $i === 0 ? 'active' : '' }}">
                        <i class="fa-solid {{ $icon }}"></i>
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endenv

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <label for="email">Institutional Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    autocomplete="username" placeholder="name@lcc.edu.ph">
            </div>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    placeholder="••••••••">
                <button type="button" id="togglePassword" class="eye-btn" aria-label="Show or hide password">
                    <i id="eyeIcon" class="fa-solid fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="auth-row">
            <label class="check">
                <input type="checkbox" name="remember"> Remember account
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Log In to System <i class="fa-solid fa-arrow-right"></i>
        </button>
    </form>

    @if (Route::has('register') && config('school.allow_public_registration'))
        <div class="auth-foot">
            No account yet? <a href="{{ route('register') }}">Register as Requester</a>
        </div>
    @endif

    @push('scripts')
    <script>
        // Show / hide password
        document.getElementById('togglePassword').addEventListener('click', function () {
            var input = document.getElementById('password');
            var icon = document.getElementById('eyeIcon');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        });

        // Local-only role tiles: fill the test account of the chosen role
        document.querySelectorAll('.role-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.role-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('email').value = btn.dataset.email;
                document.getElementById('password').value = 'password';
            });
        });
    </script>
    @endpush
</x-guest-layout>
