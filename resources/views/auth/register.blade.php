@php
    $current = old('requester_type', $defaultType);
    if (! isset($types[$current])) { $current = $defaultType; }
    $cur = $types[$current];
    $domain = trim((string) config('school.registration.email_domain'));

    // What the page script needs to swap the fields when another option is chosen (same table the server validates with).
    $emailHelp = fn ($t) => ($t['school_email'] ?? true) && $domain !== ''
        ? "Use your school email (name@{$domain})."
        : 'Use an email address you can read. The office will review your request.';
    $jsRules = collect($types)->map(fn ($t) => [
        'aff_label' => $t['affiliation']['label'],
        'aff_ph' => $t['affiliation']['placeholder'],
        'aff_help' => $t['affiliation']['help'],
        'aff' => (bool) $t['affiliation']['required'],
        'purpose_ph' => $t['purpose']['placeholder'],
        'purpose' => (bool) $t['purpose']['required'],
        'dept' => (bool) ($t['department'] ?? false),
        'email_help' => $emailHelp($t),
    ]);
@endphp
<x-guest-layout wide>
    <link rel="stylesheet" href="{{ asset('css/register.css') }}?v={{ @filemtime(public_path('css/register.css')) }}">
    <script src="{{ asset('js/register.js') }}?v={{ @filemtime(public_path('js/register.js')) }}" defer></script>

    <div class="reg">
        <p class="eyebrow">Requester registration</p>
        <h1 class="form-title">Request an account</h1>
        <p class="form-sub">
            @if (config('school.registration.require_approval'))
                A Maintenance Admin must approve your account before you can sign in.
            @else
                Fill in the form to create your account.
            @endif
            @if (config('school.registration.require_verification'))
                We will first verify your e-mail address.
            @endif
        </p>

        <form method="POST" action="{{ route('register') }}" data-reg-rules="{{ json_encode($jsRules, JSON_UNESCAPED_UNICODE) }}">
            @csrf

            <section class="reg-sec">
                <h2 class="reg-step"><b>1</b> Who are you?</h2>
                <div class="rt-grid" role="radiogroup" aria-label="I am a">
                    @foreach ($types as $key => $t)
                        <label class="rt-opt">
                            <input type="radio" name="requester_type" value="{{ $key }}" @checked($current === $key)>
                            <i class="fa-solid {{ $t['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $t['label'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('requester_type')<div class="field-err">{{ $message }}</div>@enderror
            </section>

            <section class="reg-sec">
                <h2 class="reg-step"><b>2</b> Your details</h2>

                <div class="field">
                    <label for="name">Full name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
                    @error('name')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                    <div class="hint" data-reg-email-help>{{ $emailHelp($cur) }}</div>
                    @error('email')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field" data-reg-field="department" @if (! ($cur['department'] ?? false)) hidden @endif>
                    <label for="department_id">Department <span class="hint" style="display:inline">(optional)</span></label>
                    <select id="department_id" name="department_id">
                        <option value="">-- Select department --</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field" data-reg-field="affiliation">
                    <label for="affiliation">
                        <span data-reg-label>{{ $cur['affiliation']['label'] }}</span>
                        <span class="hint" style="display:inline" data-reg-optional @if ($cur['affiliation']['required']) hidden @endif>(optional)</span>
                    </label>
                    <input id="affiliation" type="text" name="affiliation" maxlength="190" value="{{ old('affiliation') }}"
                           placeholder="{{ $cur['affiliation']['placeholder'] }}" @required($cur['affiliation']['required'])>
                    <div class="hint" data-reg-help>{{ $cur['affiliation']['help'] }}</div>
                    @error('affiliation')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field" data-reg-field="purpose">
                    <label for="purpose">
                        Why do you need an account?
                        <span class="hint" style="display:inline" data-reg-optional @if ($cur['purpose']['required']) hidden @endif>(optional)</span>
                    </label>
                    <textarea id="purpose" name="purpose" rows="3" maxlength="500" style="min-height:84px" data-counter="500"
                              placeholder="{{ $cur['purpose']['placeholder'] }}" @required($cur['purpose']['required'])>{{ old('purpose') }}</textarea>
                    <div class="hint"><span data-counter-for="purpose">0</span> / 500 characters</div>
                    @error('purpose')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </section>

            <section class="reg-sec">
                <h2 class="reg-step"><b>3</b> Secure your account</h2>

                <div class="reg-2col">
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                        @error('password')<div class="field-err">{{ $message }}</div>@enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                        @error('password_confirmation')<div class="field-err">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="meter" data-meter-for="password" aria-hidden="true"><span></span></div>
                <div class="hint" data-meter-text="password">At least 8 characters.</div>
            </section>

            <button type="submit" class="btn btn-primary btn-block reg-submit">Submit request</button>
        </form>

        <div class="auth-foot">
            <a href="{{ route('login') }}">Already have an account? Sign in</a>
        </div>
    </div>
</x-guest-layout>