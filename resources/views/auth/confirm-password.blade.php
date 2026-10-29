<x-guest-layout>
    <h1 class="form-title">Confirm <span>password</span></h1>
    <p class="form-sub">This is a secure area of the application. Please confirm your password before continuing.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            @error('password')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Confirm <i class="fa-solid fa-arrow-right"></i>
        </button>
    </form>
</x-guest-layout>
