<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Departments</h2>
    </x-slot>

    <div class="content p6-wrap">
        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif
        @error('transfer_to')
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ $message }}</span></div>
        @enderror

        <p class="p6-note">
            Add and edit the school's offices. An <b>inactive</b> department is no longer offered on the new-ticket
            and registration forms, but its old tickets keep showing it. <b>Deactivating</b> is refused while
            there are open tickets, unless you choose another active department to move them to. A department
            with <b>any</b> ticket can never be hard-deleted. Every change is written to the <b>Audit log</b>.
            Only the <b>System Administrator</b> has this page (<code>manage_departments</code>).
        </p>

        <div class="card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div>
                <h3 class="p6-card-t" style="margin:0">{{ $departments->count() }} department(s)</h3>
                <div class="p6-hint">{{ $departments->where('is_active', true)->count() }} active</div>
            </div>
            <a href="{{ route('admin.departments.create') }}" class="btn btn-sm btn-primary" style="margin-left:auto">
                <i class="fa-solid fa-building-circle-plus"></i> Add a department
            </a>
        </div>

        <div class="card table-wrap">
            <table class="stack">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Code</th>
                        <th>Head</th>
                        <th>People</th>
                        <th>Open tickets</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($departments as $d)
                        @php
                            $open = (int) $d->open_tickets_count;
                            $total = (int) $d->total_tickets_count;
                        @endphp
                        <tr @unless($d->is_active) style="opacity:.72" @endunless>
                            <td data-label="Department" style="word-break:break-word">
                                <b>{{ $d->name }}</b>
                                @unless($d->is_service_department)
                                    <div class="p6-hint">Files only (no work queue)</div>
                                @endunless
                                @if ($d->description)<div class="p6-hint">{{ \Illuminate\Support\Str::limit($d->description, 70) }}</div>@endif
                            </td>
                            <td data-label="Code">{{ $d->code ?? '—' }}</td>
                            <td data-label="Head">{{ $d->head?->name ?? '—' }}</td>
                            <td data-label="People">{{ $d->users_count }}</td>
                            <td data-label="Open tickets">
                                <b>{{ $open }}</b>
                                <div class="p6-hint">{{ $total }} total</div>
                            </td>
                            <td data-label="Status">
                                @if ($d->is_active)
                                    <span class="badge pr-normal nocap">Active</span>
                                @else
                                    <span class="badge nocap ab-file">Inactive</span>
                                @endif
                            </td>
                            <td data-label="Actions" style="text-align:right">
                                <a class="btn btn-sm" href="{{ route('admin.departments.edit', $d) }}">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>

                                @if ($d->is_active)
                                    <form method="POST" action="{{ route('admin.departments.deactivate', $d) }}"
                                          style="display:inline;margin-top:6px"
                                          onsubmit="return confirm('Deactivate {{ $d->name }}? It will no longer be offered on new tickets.')">
                                        @csrf
                                        @method('PATCH')
                                        @if ($open > 0)
                                            <select name="transfer_to" required aria-label="Move open tickets to"
                                                    style="max-width:210px;margin-bottom:6px">
                                                <option value="">Move {{ $open }} open ticket(s) to…</option>
                                                @foreach ($targets as $t)
                                                    @if ($t->id !== $d->id)
                                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        @endif
                                        <button class="btn btn-sm btn-danger"><i class="fa-solid fa-power-off"></i> Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.departments.activate', $d) }}" style="display:inline">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm btn-ok"><i class="fa-solid fa-power-off"></i> Activate</button>
                                    </form>
                                @endif

                                @if ($total === 0)
                                    <form method="POST" action="{{ route('admin.departments.destroy', $d) }}"
                                          style="display:inline;margin-top:6px"
                                          onsubmit="return confirm('Permanently delete {{ $d->name }}? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty"><i class="fa-solid fa-building"></i>No departments yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
