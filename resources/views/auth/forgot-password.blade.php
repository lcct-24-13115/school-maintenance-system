<x-guest-layout>
    <h1 class="form-title">Forgot <span>password?</span></h1>
    <p class="form-sub">Enter your email address and we will send you a link to choose a new password.</p>

    @if (session('status'))
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>
            @error('email')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Email Password Reset Link <i class="fa-solid fa-paper-plane"></i>
        </button>
    </form>

    <div class="auth-foot">
        <a href="{{ route('login') }}"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
    </div>
</x-guest-layout>
