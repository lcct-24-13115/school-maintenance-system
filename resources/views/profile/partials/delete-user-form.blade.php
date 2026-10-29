<section class="p6-sec">
    <header class="card-intro">
        <h3 class="p6-card-t">Delete account</h3>
        <p class="p6-card-s">This removes your account and its data permanently. Save anything you need first.</p>
    </header>

    <div>
        <button type="button" class="btn btn-danger" onclick="document.getElementById('confirm-user-deletion').showModal()">
            <i class="fa-solid fa-trash-can"></i> Delete account
        </button>
    </div>

    {{-- native <dialog>: closes with Esc, traps focus, needs no JS library --}}
    <dialog id="confirm-user-deletion" class="p6-dialog" onclick="if(event.target===this)this.close()">
        <form method="post" action="{{ route('profile.destroy') }}" class="modal">
            @csrf
            @method('delete')

            <h3>Delete your account?</h3>
            <p class="form-sub">All of your data will be permanently deleted. Enter your password to confirm.</p>

            <div class="field" style="margin-top:14px">
                <label for="delete_password">Password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock ic"></i>
                    <input id="delete_password" type="password" name="password" placeholder="Your password" autocomplete="current-password">
                </div>
                @foreach ($errors->userDeletion->get('password') as $m)<div class="field-err">{{ $m }}</div>@endforeach
            </div>

            <div class="modal-acts">
                <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash-can"></i> Delete account</button>
            </div>
        </form>
    </dialog>

    @if ($errors->userDeletion->isNotEmpty())
        <script>document.getElementById('confirm-user-deletion').showModal()</script>
    @endif
</section>
