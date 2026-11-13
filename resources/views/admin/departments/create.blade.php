<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Add a department</h2>
    </x-slot>

    <div class="content p6-wrap">
        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <p class="p6-note">
            New departments start <b>active</b>. A department can be deactivated later, but while it has open
            tickets the admin must first pick another department to move them to. Every change is written to
            the <b>Audit log</b>.
        </p>

        <div class="card">
            <h3 class="p6-card-t">Department details</h3>
            @include('admin.departments._form', [
                'action' => route('admin.departments.store'),
                'method' => 'POST',
            ])
        </div>
    </div>
</x-app-layout>
