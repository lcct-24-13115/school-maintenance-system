{{-- Sidebar (LCCT design). Same menu items and permission checks as the old top navigation. --}}
@php $u = auth()->user(); @endphp

<aside class="sidebar" :class="{ 'open': sb }">
    <div class="sb-head">
        <img src="{{ asset('images/lcct-logo.png') }}" alt="LCCT seal">
        <div>
            <div class="brand">LCCT <span>Maintenance</span></div>
            <small>Ticketing System</small>
        </div>
    </div>

    <nav class="nav">
        <div class="nav-sec">Main</div>

        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard', '*.dashboard') ? 'active' : '' }}">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>

        <a href="{{ route('tickets.index') }}" class="{{ request()->routeIs('tickets.*') && ! request()->routeIs('tickets.history') ? 'active' : '' }}">
            <i class="fa-solid fa-ticket"></i> My Tickets
        </a>

        <a href="{{ route('tickets.history') }}" class="{{ request()->routeIs('tickets.history') ? 'active' : '' }}">
            <i class="fa-solid fa-clock-rotate-left"></i> Ticket History
        </a>

        @can('create', \App\Models\MaintenanceRequest::class)
            <a href="{{ route('repairs.create') }}" class="{{ request()->routeIs('repairs.*') ? 'active' : '' }}">
                <i class="fa-solid fa-circle-plus"></i> New request
            </a>
        @endcan

        @if ($u->canAuthorize())
            <a href="{{ route('approvals.index') }}" class="{{ request()->routeIs('approvals.*') ? 'active' : '' }}">
                <i class="fa-solid fa-clipboard-check"></i> Approvals
            </a>
        @endif

        <a href="{{ route('notifications.index') }}" class="{{ request()->routeIs('notifications.*') ? 'active' : '' }}">
            <i class="fa-solid fa-bell"></i> Notifications
        </a>

        <a href="{{ route('help.index') }}" class="{{ request()->routeIs('help.*') ? 'active' : '' }}">
            <i class="fa-solid fa-circle-question"></i> Help &amp; FAQ
        </a>

        @if ($u->can_do('view_analytics') || $u->can_do('manage_settings') || $u->can_do('manage_categories') || $u->can_do('manage_users') || $u->can_do('manage_qr_codes') || $u->can_do('view_audit_logs') || $u->can_do('view_email_log') || $u->canManageFaq())
            <div class="nav-sec">Administration</div>
        @endif

        @if ($u->can_do('view_analytics'))
            <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="fa-solid fa-chart-column"></i> Reports
            </a>
        @endif

        @if ($u->can_do('manage_users'))
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <i class="fa-solid fa-users"></i> Users
                @php($pendingUsers = \App\Models\User::query()->pending()->count())
                @if ($pendingUsers > 0)
                    <span class="badge nocap ab-file" style="margin-left:auto" title="Accounts waiting for approval">{{ $pendingUsers }}</span>
                @endif
            </a>
        @endif

        @if ($u->can_do('manage_settings'))
            <a href="{{ route('admin.settings.edit') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                <i class="fa-solid fa-gear"></i> Settings
            </a>
        @endif

        @if ($u->can_do('manage_categories'))
            <a href="{{ route('admin.categories.index') }}" class="{{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                <i class="fa-solid fa-tags"></i> Categories
            </a>
        @endif

        @if ($u->canManageFaq())
            <a href="{{ route('admin.faq.index') }}" class="{{ request()->routeIs('admin.faq.*') ? 'active' : '' }}">
                <i class="fa-solid fa-pen-to-square"></i> FAQ manager
            </a>
        @endif

        @if ($u->can_do('manage_qr_codes'))
            <a href="{{ route('admin.qr-codes.index') }}" class="{{ request()->routeIs('admin.qr-codes.*') ? 'active' : '' }}">
                <i class="fa-solid fa-qrcode"></i> QR codes
            </a>
        @endif

        @if ($u->can_do('view_audit_logs'))
            <a href="{{ route('admin.audit-logs.index') }}" class="{{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">
                <i class="fa-solid fa-clock-rotate-left"></i> Audit log
            </a>
        @endif

        @if ($u->can_do('view_email_log'))
            <a href="{{ route('admin.email-log.index') }}" class="{{ request()->routeIs('admin.email-log.*') ? 'active' : '' }}">
                <i class="fa-solid fa-envelope-open-text"></i> E-mail log
            </a>
        @endif
    </nav>

    <div class="sb-credit">
        <img src="{{ asset('images/intel-logo.png') }}" alt="INTEL">
        <span>Developed by<b>INTEL</b></span>
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
