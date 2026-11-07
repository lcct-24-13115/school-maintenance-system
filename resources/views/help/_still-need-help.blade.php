@php
    $helpHours = trim((string) \App\Models\Setting::get('help_office_hours', ''));
    $helpEmail = trim((string) \App\Models\Setting::get('help_contact_email', ''));
    $helpEmailOk = filter_var($helpEmail, FILTER_VALIDATE_EMAIL) !== false;
    $canSendRequest = ! $isGuest && auth()->user()?->can('create', \App\Models\MaintenanceRequest::class);
@endphp

<aside class="help-more" id="still-need-help" aria-labelledby="help-more-title">
    <h2 id="help-more-title">Still need help?</h2>
    <p>Cannot find your answer? The maintenance office will gladly help you.</p>

    @if ($helpHours !== '' || $helpEmailOk)
        <ul class="help-contact">
            @if ($helpHours !== '')
                <li><i class="fa-regular fa-clock" aria-hidden="true"></i><span><b>Office hours</b>{{ $helpHours }}</span></li>
            @endif
            @if ($helpEmailOk)
                <li><i class="fa-regular fa-envelope" aria-hidden="true"></i><span><b>E-mail</b><a href="mailto:{{ $helpEmail }}">{{ $helpEmail }}</a></span></li>
            @endif
        </ul>
    @endif

    @if ($canSendRequest)
        <a class="btn btn-primary" href="{{ route('repairs.create') }}"><i class="fa-solid fa-screwdriver-wrench"></i> Send a repair request</a>
    @elseif ($isGuest)
        <p class="help-signin">To send a repair request, <a href="{{ route('login') }}">sign in</a> first.</p>
    @endif
</aside>