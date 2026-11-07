<x-guest-layout>
    <h1 class="form-title">Forgot <span>password?</span></h1>
    <p class="form-sub">Confirm who you are, then choose your new password right here.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            @error('email')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="employee_no">Employee / ID number</label>
            <div class="input-wrap">
                <i class="fa-solid fa-id-card ic"></i>
                <input id="employee_no" type="text" name="employee_no" value="{{ old('employee_no') }}" required autocomplete="off">
            </div>
            @error('employee_no')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="password">New password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>
            @error('password')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Change Password <i class="fa-solid fa-check"></i>
        </button>
    </form>

    <div class="auth-foot">
        <a href="{{ route('login') }}"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
    </div>
</x-guest-layout>
