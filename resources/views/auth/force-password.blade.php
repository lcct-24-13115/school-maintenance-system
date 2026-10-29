<x-guest-layout>
    <h1 class="form-title">Choose a new <span>password</span></h1>
    <p class="form-sub">Your account uses a temporary password. Choose your own before you continue.</p>

    <form method="POST" action="{{ route('password.force.update') }}">
        @csrf
        @method('PUT')

        <div class="field">
            <label for="current_password">Temporary password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-key ic"></i>
                <input id="current_password" type="password" name="current_password" required autofocus autocomplete="current-password">
            </div>
            @error('current_password')<div class="field-err">{{ $message }}</div>@enderror
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
            Save and continue <i class="fa-solid fa-check"></i>
        </button>
    </form>

    <div class="auth-foot">
        <a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
            <i class="fa-solid fa-arrow-left"></i> Log out
        </a>
    </div>
    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">@csrf</form>
</x-guest-layout>
