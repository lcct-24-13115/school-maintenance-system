{{-- Sidebar (LCCT design). Same menu items and permission checks as the old top navigation. --}}
@php
    $u = auth()->user();
    // "Your office" card: the signed-in user's department; accounts without one (e.g. the Maintenance head) show the maintenance office.
    $office = $u->department;
    $officeName = $office?->name ?? 'Maintenance Office';
    $officeCode = $office && $office->code && strcasecmp($office->code, $office->name) !== 0 ? $office->code : null;
@endphp

<aside class="sidebar" :class="{ 'open': sb }">
    <div class="sb-head">
        <img src="{{ asset('images/lcct-logo.png') }}" alt="LCCT seal">
        <div>
            <div class="brand">LCCT <span>Maintenance</span></div>
            <small>Ticketing System</small>
        </div>
    </div>

    <div class="sb-office" title="Your office: {{ $officeName }}">
        <span class="sb-office-ic"><i class="fa-solid fa-building"></i></span>
        <span class="sb-office-txt">
            <small>Your office</small>
            <strong>{{ $officeName }}@if ($officeCode) <span class="sb-office-abbr">({{ $officeCode }})</span>@endif</strong>
        </span>
    </div>

    <nav class="nav">
        <div class="nav-sec">Main</div>

        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard', '*.dashboard') ? 'active' : '' }}">
            <i class="fa-solid fa-gauge-high"></i> <span class="nl">Dashboard</span>
        </a>

        <a href="{{ route('tickets.index') }}" class="{{ request()->routeIs('tickets.*') && ! request()->routeIs('tickets.history') ? 'active' : '' }}">
            <i class="fa-solid fa-ticket"></i> <span class="nl">My Tickets</span>
        </a>

        <a href="{{ route('tickets.history') }}" class="{{ request()->routeIs('tickets.history') ? 'active' : '' }}">
            <i class="fa-solid fa-clock-rotate-left"></i> <span class="nl">Ticket History</span>
        </a>

        @can('create', \App\Models\MaintenanceRequest::class)
            <a href="{{ route('repairs.create') }}" class="{{ request()->routeIs('repairs.*') ? 'active' : '' }}">
                <i class="fa-solid fa-circle-plus"></i> <span class="nl">New request</span>
            </a>
            <a href="{{ route('tickets.create') }}" class="{{ request()->routeIs('tickets.create') ? 'active' : '' }}">
                <i class="fa-solid fa-paper-plane"></i> <span class="nl">New ticket</span>
            </a>
        @endcan

        @if ($u->canAuthorize())
            <a href="{{ route('approvals.index') }}" class="{{ request()->routeIs('approvals.*') ? 'active' : '' }}">
                <i class="fa-solid fa-clipboard-check"></i> <span class="nl">Approvals</span>
            </a>
        @endif

        <a href="{{ route('notifications.index') }}" class="{{ request()->routeIs('notifications.*') ? 'active' : '' }}">
            <i class="fa-solid fa-bell"></i> <span class="nl">Notifications</span>
        </a>

        <a href="{{ route('help.index') }}" class="{{ request()->routeIs('help.*') ? 'active' : '' }}">
            <i class="fa-solid fa-circle-question"></i> <span class="nl">Help &amp; FAQ</span>
        </a>

        @if ($u->can_do('view_analytics') || $u->can_do('manage_qr_codes'))
            <div class="nav-sec">Office</div>
        @endif

        @if ($u->can_do('view_analytics'))
            <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="fa-solid fa-chart-column"></i> <span class="nl">Reports</span>
            </a>
        @endif

        @if ($u->can_do('manage_qr_codes'))
            <a href="{{ route('admin.qr-codes.index') }}" class="{{ request()->routeIs('admin.qr-codes.*') ? 'active' : '' }}">
                <i class="fa-solid fa-qrcode"></i> <span class="nl">QR codes</span>
            </a>
        @endif

        @if ($u->can_do('manage_settings') || $u->can_do('manage_categories') || $u->can_do('manage_users') || $u->can_do('view_audit_logs') || $u->can_do('view_email_log') || $u->canManageFaq())
            <div class="nav-sec">Administration</div>
        @endif

        @if ($u->can_do('manage_users'))
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <i class="fa-solid fa-users"></i> <span class="nl">Users</span>
                @php($pendingUsers = \App\Models\User::query()->pending()->count())
                <span id="pending-users-badge" class="badge nocap ab-file"
                          data-pending-url="{{ route('admin.users.pending-count') }}" data-interval="{{ config('school.poll_seconds') }}"
                          style="margin-left:auto;@if ($pendingUsers < 1) display:none @endif" title="Accounts waiting for approval">{{ $pendingUsers }}</span>
            </a>
        @endif

        @if ($u->can_do('manage_settings'))
            <a href="{{ route('admin.settings.edit') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                <i class="fa-solid fa-gear"></i> <span class="nl">Settings</span>
            </a>
        @endif

        @if ($u->can_do('manage_categories'))
            <a href="{{ route('admin.categories.index') }}" class="{{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                <i class="fa-solid fa-tags"></i> <span class="nl">Categories</span>
            </a>
        @endif

        @if ($u->canManageFaq())
            <a href="{{ route('admin.faq.index') }}" class="{{ request()->routeIs('admin.faq.*') ? 'active' : '' }}">
                <i class="fa-solid fa-pen-to-square"></i> <span class="nl">FAQ manager</span>
            </a>
        @endif

        @if ($u->can_do('view_audit_logs'))
            <a href="{{ route('admin.audit-logs.index') }}" class="{{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">
                <i class="fa-solid fa-clock-rotate-left"></i> <span class="nl">Audit log</span>
            </a>
        @endif

        @if ($u->can_do('view_email_log'))
            <a href="{{ route('admin.email-log.index') }}" class="{{ request()->routeIs('admin.email-log.*') ? 'active' : '' }}">
                <i class="fa-solid fa-envelope-open-text"></i> <span class="nl">E-mail log</span>
            </a>
        @endif
    </nav>

    <div class="sb-credit">
        <img src="{{ asset('images/intel-logo.png') }}" alt="INTEL">
        <span class="nl">Developed by<b>INTEL</b></span>
    </div>

    <div class="sb-links">
        <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">
            <i class="fa-solid fa-user-gear"></i> <span class="nl">My Profile</span>
        </a>
        <button type="button" class="sb-toggle" aria-expanded="true" title="Collapse sidebar">
            <i class="fa-solid fa-angles-left"></i> <span class="nl">Collapse</span>
        </button>
    </div>
    <div class="sb-user">
        <x-avatar :user="$u" />
        <div class="who">
            <div class="n">{{ $u->name }}</div>
            <div class="r">{{ $u->role?->name }}</div>
        </div>
        <a href="#" title="Log out" aria-label="Log out"
           onclick="event.preventDefault();document.getElementById('logout-form').submit();">
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</aside>
