<x-guest-layout>
    <p class="eyebrow">Welcome back</p>
    <h1 class="form-title">Sign in to your account</h1>
    <p class="form-sub">Use the email address registered with the school maintenance office.</p>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $errors->first() }}</span></div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <label for="email">Email address</label>
            <div class="input-wrap">
                <i class="fa-regular fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    autocomplete="username" placeholder="studentnumber@lcctanauan.edu.ph">
            </div>
        </div>

        <div class="field">
            <div class="label-row">
                <label for="password">Password</label>
                @if (Route::has('password.request'))
                    <a class="small-link" href="{{ route('password.request') }}">Forgot password?</a>
                @endif
            </div>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    placeholder="Enter your password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    @if (Route::has('register') && config('school.allow_public_registration'))
        <div class="auth-divider"><span>New to the system?</span></div>
        <a class="btn btn-secondary btn-block" href="{{ route('register') }}">
            <i class="fa-solid fa-user-plus"></i> Create an account
        </a>
    @endif

    <p class="auth-secure"><i class="fa-solid fa-shield-halved"></i> Secure sign-in · session ends when idle</p>
</x-guest-layout>
