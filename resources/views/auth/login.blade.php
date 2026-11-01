<x-guest-layout>
    <h1 class="form-title">Welcome <span>back</span></h1>
    <p class="form-sub">Log in with your institutional account. We will take you to your dashboard.</p>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $errors->first() }}</span></div>
    @endif

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
</x-guest-layout>
