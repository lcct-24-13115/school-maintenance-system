@php
    $typeLabel = $log->type ? \Illuminate\Support\Str::headline($log->type) : 'Other';
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/emaillog.css') }}?v={{ @filemtime(public_path('css/emaillog.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">E-mail details</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <p class="p6-note">
            <a href="{{ route('admin.email-log.index') }}"><i class="fa-solid fa-arrow-left"></i> Back to the e-mail log</a>
        </p>

        <div class="card">
            <div class="el-head">
                <h3 class="p6-card-t" style="margin:0">{{ $log->subject }}</h3>
                <span class="badge nocap el-{{ $log->effectiveStatus() }}">{{ $log->statusLabel() }}</span>
            </div>

            @if ($fakeMailer && $log->status === 'delivered')
                <p class="p6-card-s">This e-mail was only written to the log (MAIL_MAILER={{ $log->mailer }}); it was not sent to a real inbox.</p>
            @endif

            <dl class="el-dl">
                <div><dt>To</dt><dd>{{ $log->to_name ? $log->to_name.' · ' : '' }}{{ $log->to_email }}</dd></div>
                <div><dt>Kind</dt><dd>{{ $typeLabel }}</dd></div>
                @if ($log->ticket_no)
                    <div><dt>Ticket</dt><dd>{{ $log->ticket_no }}</dd></div>
                @endif
                <div><dt>Created</dt><dd>{{ $log->created_at?->format('M j, Y g:i:s A') }}</dd></div>
                <div><dt>Delivered</dt><dd>{{ $log->delivered_at?->format('M j, Y g:i:s A') ?? '—' }}</dd></div>
                <div><dt>Tries</dt><dd>{{ $log->attempts }}</dd></div>
                <div><dt>Mailer</dt><dd>{{ $log->mailer ?? '—' }}</dd></div>
                <div><dt>Message ID</dt><dd class="mono">{{ $log->message_id ?? '—' }}</dd></div>
            </dl>

            @if ($log->error)
                <div class="alert err" role="alert" style="margin-top:14px">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><b>Error:</b> {{ $log->error }}</span>
                </div>
            @endif

            @if ($log->canRetry())
                <form method="POST" action="{{ route('admin.email-log.retry', $log->id) }}" class="p6-bar">
                    @csrf
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-rotate-right"></i> Send again</button>
                </form>
            @endif
        </div>

        <div class="card">
            <h3 class="p6-card-t">Content</h3>
            @if (filled($log->body_html))
                {{-- sandbox="" = no scripts, no forms, no navigation: the stored HTML can only be looked at --}}
                <iframe class="el-preview" sandbox="" title="Preview of the e-mail" srcdoc="{{ $log->body_html }}"></iframe>
            @else
                <p class="p6-card-s">The content of this kind of e-mail is not kept (it can hold a private link).</p>
            @endif
        </div>
    </div>
</x-app-layout>
