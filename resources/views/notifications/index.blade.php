<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Notifications</h2>
    </x-slot>

    <div class="content">
        <div class="p6-bar" style="margin:0 0 18px">
            <p class="p6-note" style="margin:0">
                @php
                    $unread = auth()->user()->unreadNotifications()->count();
                @endphp
                {{ $unread > 0 ? $unread.' unread' : 'You are all caught up.' }}
            </p>
            <form method="POST" action="{{ route('notifications.readAll') }}" class="push">
                @csrf
                <button type="submit" class="btn btn-sm"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
            </form>
        </div>

        <div class="p6-notifs">
            @forelse ($notifications as $n)
                @php
                    $d = $n->data;
                    $urgent = (bool) ($d['urgent'] ?? false);
                    $isNew = is_null($n->read_at);
                @endphp
                <article class="p6-n {{ $urgent ? 'urgent' : ($isNew ? 'unread' : '') }}">
                    <span class="ico"><i class="fa-solid {{ $urgent ? 'fa-triangle-exclamation' : 'fa-bell' }}"></i></span>
                    <div class="bd">
                        <div class="tp">
                            @if ($isNew)<span class="p6-new" title="Unread"></span>@endif
                            <span>{{ $d['form_label'] ?? 'Notice' }}@if (!empty($d['reference_no'])) · {{ $d['reference_no'] }}@endif</span>
                            @if ($urgent)<span class="badge pr-urgent">Emergency</span>@endif
                        </div>
                        <div class="tt">{{ $d['title'] ?? '' }}</div>
                        @if (!empty($d['requester_name']) || !empty($d['department']))
                            <div class="tx">{{ $d['requester_name'] ?? '' }}@if (!empty($d['department'])) · {{ $d['department'] }}@endif</div>
                        @endif
                        @if (!empty($d['message']))<div class="tx">{{ $d['message'] }}</div>@endif
                        <div class="tm"><i class="fa-regular fa-clock"></i>{{ $n->created_at->diffForHumans() }}</div>
                    </div>
                </article>
            @empty
                <div class="card"><x-empty-state icon="fa-regular fa-bell" title="No notifications yet.">New alerts will show up here as they come in.</x-empty-state></div>
            @endforelse
        </div>

        {{ $notifications->links() }}
    </div>
</x-app-layout>
