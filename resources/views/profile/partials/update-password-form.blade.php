<section class="p6-sec">
    <header class="card-intro">
        <h3 class="p6-card-t">Change password</h3>
        <p class="p6-card-s">Use a long, random password to keep your account secure.</p>
    </header>

    <form method="post" action="{{ route('password.update') }}">
        @csrf
        @method('put')

        <div class="field">
            <label for="update_password_current_password">Current password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock ic"></i>
                <input id="update_password_current_password" type="password" name="current_password" autocomplete="current-password">
            </div>
            @foreach ($errors->updatePassword->get('current_password') as $m)<div class="field-err">{{ $m }}</div>@endforeach
        </div>

        <div class="row2">
            <div class="field">
                <label for="update_password_password">New password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-key ic"></i>
                    <input id="update_password_password" type="password" name="password" autocomplete="new-password">
                </div>
                @foreach ($errors->updatePassword->get('password') as $m)<div class="field-err">{{ $m }}</div>@endforeach
            </div>

            <div class="field">
                <label for="update_password_password_confirmation">Confirm new password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-key ic"></i>
                    <input id="update_password_password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
                </div>
                @foreach ($errors->updatePassword->get('password_confirmation') as $m)<div class="field-err">{{ $m }}</div>@endforeach
            </div>
        </div>

        <div class="p6-save">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update password</button>

            @if (session('status') === 'password-updated')
                <span class="p6-saved" role="status" id="saved-password"><i class="fa-solid fa-circle-check"></i>Password updated</span>
                <script>setTimeout(function(){var e=document.getElementById('saved-password');if(e)e.style.display='none'},3000)</script>
            @endif
        </div>
    </form>
</section>
