<x-guest-layout>
    <h1 class="form-title">Reset <span>password</span></h1>
    <p class="form-sub">Choose a new password for your account.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username">
            </div>
            @error('email')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="password">New Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>
            @error('password')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            @error('password_confirmation')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Reset Password <i class="fa-solid fa-check"></i>
        </button>
    </form>
</x-guest-layout>
