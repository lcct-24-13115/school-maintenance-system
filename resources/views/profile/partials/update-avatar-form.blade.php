<section class="p6-sec">
    <header class="card-intro">
        <h3 class="p6-card-t">Profile photo</h3>
        <p class="p6-card-s">Shown in the menu and next to your name in ticket history. JPG, PNG or WEBP, up to 3 MB.</p>
    </header>

    <div>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:16px">
            <x-avatar :user="$user" :size="84" />
            <div class="hint">{{ $user->avatar_path ? 'Your current photo.' : 'No photo yet. Your initial is shown instead.' }}</div>
        </div>

        <form method="post" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="field">
                <label for="avatar">Choose a photo</label>
                <input id="avatar" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                @error('avatar')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload photo</button>

                @if (in_array(session('status'), ['avatar-updated', 'avatar-removed'], true))
                    <span class="p6-saved" role="status" id="saved-avatar"><i class="fa-solid fa-circle-check"></i>{{ session('status') === 'avatar-updated' ? 'Photo updated' : 'Photo removed' }}</span>
                    <script>setTimeout(function(){var e=document.getElementById('saved-avatar');if(e)e.style.display='none'},3000)</script>
                @endif
            </div>
        </form>

        @if ($user->avatar_path)
            <form method="post" action="{{ route('profile.avatar.destroy') }}" style="margin-top:10px"
                  onsubmit="return confirm('Remove your profile photo?')">
                @csrf
                @method('delete')
                <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Remove photo</button>
            </form>
        @endif
    </div>
</section>
