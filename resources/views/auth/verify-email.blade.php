<x-guest-layout>
    <h1 class="form-title">Verify your <span>email</span></h1>
    <p class="form-sub">Thanks for signing up! Please verify your email address by clicking the link we just emailed to you. If you didn't receive it, we can send another.</p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>A new verification link has been sent to the email address you provided during registration.</span></div>
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
