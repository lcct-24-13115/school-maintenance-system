<x-guest-layout>
    <h1 class="form-title">Welcome <span>back</span></h1>
    <p class="form-sub">Sign in with your institutional email. We'll take you to the right place automatically.</p>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $errors->first() }}</span></div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <label for="email">Institutional email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    autocomplete="username" placeholder="studentnumber@lcctanauan.edu.ph">
            </div>
            <p class="hint">Your email must look like studentnumber@lcctanauan.edu.ph.</p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    placeholder="Enter your password">
            </div>
        </div>

        @if (Route::has('password.request'))
            <div class="auth-row auth-row-end">
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
        @endif

        <button type="submit" class="btn btn-primary btn-block">
            Sign in <i class="fa-solid fa-arrow-right"></i>
        </button>
    </form>

    @if (Route::has('register'))
        <div class="auth-foot">
            New student? <a href="{{ route('register') }}">Create an account</a>
        </div>
    @endif
</x-guest-layout>