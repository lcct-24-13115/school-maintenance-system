<x-guest-layout wide>
    <h1 class="form-title">Create <span>account</span></h1>
    <p class="form-sub">
        Register as a Requester to file maintenance tickets.
        @if (config('school.registration.require_approval', true))
            A Maintenance Admin must approve your account before you can sign in.
        @endif
    </p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="field">
            <label for="name">Full Name</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user ic"></i>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
            </div>
            @error('name')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope ic"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </div>
            @error('email')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">-- Select department --</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            @error('department_id')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="row2">
            <div class="field">
                <label for="password">Password</label>
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
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Register <i class="fa-solid fa-arrow-right"></i>
        </button>
    </form>

    <div class="auth-foot">
        Already registered? <a href="{{ route('login') }}">Log in</a>
    </div>
</x-guest-layout>
