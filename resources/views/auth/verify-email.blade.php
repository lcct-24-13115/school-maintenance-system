<x-guest-layout>
    <h1 class="form-title">Verify your <span>email</span></h1>
    <p class="form-sub">
        We emailed a confirmation link to <b>{{ auth()->user()->email }}</b>. Open it to activate your account.
        The link works for {{ rtrim(rtrim(number_format(config('auth.verification.expire') / 60, 1), '0'), '.') }} hours.
        Cannot find it? Check your spam folder, or send a new one.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>A new verification link has been sent to {{ auth()->user()->email }}.</span></div>
    @elseif (session('status') == 'verification-link-expired')
        <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>That link is not valid any more (it expired or was changed). Send a new one below.</span></div>
    @endif

    <div class="auth-actions">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">Resend Verification Email</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn">Log Out</button>
        </form>
    </div>
</x-guest-layout>
