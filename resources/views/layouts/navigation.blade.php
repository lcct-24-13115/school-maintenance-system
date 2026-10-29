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

        <a href="{{ route('tickets.index') }}" class="{{ request()->routeIs('tickets.*') ? 'active' : '' }}">
            <i class="fa-solid fa-ticket"></i> Tickets
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

        @if ($u->can_do('view_analytics') || $u->can_do('manage_settings') || $u->can_do('manage_categories') || $u->can_do('manage_users') || $u->can_do('view_audit_logs'))
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

        @if ($u->can_do('view_audit_logs'))
            <a href="{{ route('admin.audit-logs.index') }}" class="{{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">
                <i class="fa-solid fa-clock-rotate-left"></i> Audit log
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
