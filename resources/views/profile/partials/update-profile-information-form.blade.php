<section class="p6-sec">
    <header class="card-intro">
        <h3 class="p6-card-t">Profile information</h3>
        <p class="p6-card-s">Update your name and email address.</p>
    </header>

    <div>
        <form id="send-verification" method="post" action="{{ route('verification.send') }}">
            @csrf
        </form>

        <form method="post" action="{{ route('profile.update') }}">
            @csrf
            @method('patch')

            <div class="field">
                <label for="name">Full name</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-user ic"></i>
                    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name">
                </div>
                @error('name')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-envelope ic"></i>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                </div>
                @error('email')<div class="field-err">{{ $message }}</div>@enderror

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="p6-warn">
                        Your email address is not verified.
                        <button type="submit" form="send-verification">Send a new verification link</button>
                    </div>

                    @if (session('status') === 'verification-link-sent')
                        <div class="alert ok" style="margin:10px 0 0"><i class="fa-solid fa-circle-check"></i><span>A new verification link has been sent to your email address.</span></div>
                    @endif
                @endif
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>

                @if (session('status') === 'profile-updated')
                    <span class="p6-saved" role="status" id="saved-profile"><i class="fa-solid fa-circle-check"></i>Saved</span>
                    <script>setTimeout(function(){var e=document.getElementById('saved-profile');if(e)e.style.display='none'},3000)</script>
                @endif
            </div>
        </form>
    </div>
</section>
