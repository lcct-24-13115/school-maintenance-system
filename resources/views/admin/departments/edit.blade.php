@php
    $hasTickets = $totalTickets > 0;
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Edit department: {{ $department->name }}</h2>
    </x-slot>

    <div class="content p6-wrap">
        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <div class="card">
            <h3 class="p6-card-t">Department details</h3>
            @include('admin.departments._form', [
                'action' => route('admin.departments.update', $department),
                'method' => 'PUT',
            ])
            <div class="p6-hint">
                Active / inactive is <b>not</b> changed here: use the Activate / Deactivate action on the list,
                so the open-ticket transfer rule cannot be skipped.
            </div>
        </div>

        <div class="card">
            <h3 class="p6-card-t"><i class="fa-solid fa-users"></i> People in this department</h3>
            <p class="p6-card-s">
                Head: <b>{{ $department->head?->name ?? 'None assigned' }}</b>.
                Assign a Department Head or Staff member by changing a person's <b>role</b> and
                <b>department</b> in Admin &rsaquo; Users. Inactive departments cannot be newly assigned.
            </p>

            @if ($staff->isNotEmpty())
                <div class="table-wrap">
                    <table class="stack">
                        <thead><tr><th>Department Staff</th><th>Email</th></tr></thead>
                        <tbody>
                            @foreach ($staff as $s)
                                <tr>
                                    <td data-label="Department Staff">{{ $s->name }}</td>
                                    <td data-label="Email">{{ $s->email }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty"><i class="fa-solid fa-users"></i>No active Department Staff in this office yet.</div>
            @endif

            <p style="margin-top:14px">
                <a class="btn btn-sm" href="{{ route('admin.users.index', ['department' => $department->id]) }}">
                    <i class="fa-solid fa-user-gear"></i> Manage people
                </a>
            </p>
        </div>

        <div class="card">
            <h3 class="p6-card-t"><i class="fa-solid fa-ticket"></i> Tickets</h3>
            <p class="p6-card-s">
                <b>{{ $openTickets }}</b> open ticket(s) &middot; <b>{{ $totalTickets }}</b> ticket(s) in total
                (filed by or assigned to this department).
            </p>
            @if ($openTickets > 0)
                <div class="alert warn">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>Deactivating is blocked until you choose another ACTIVE department to move these open tickets to. Finished tickets stay here for history.</div>
                </div>
            @endif
        </div>

        <div class="card">
            <h3 class="p6-card-t"><i class="fa-solid fa-trash"></i> Delete</h3>
            @if ($hasTickets)
                <p class="p6-card-s">
                    This department has tickets, so it <b>cannot be hard-deleted</b>. Deactivate it from the list instead
                    (with a transfer target for open tickets) — the ticket history is kept.
                </p>
            @else
                <p class="p6-card-s">This department has no tickets. Deleting it is permanent; deactivating is usually safer.</p>
                <form method="POST" action="{{ route('admin.departments.destroy', $department) }}"
                      onsubmit="return confirm('Permanently delete {{ $department->name }}? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Delete department</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
