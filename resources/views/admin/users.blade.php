@php
    // After a failed "Add" the page comes back with the old input; _form says which form it was.
    $adding = old('_form') === 'create';
    $editing = old('_edit_id');
    $me = auth()->id();
    $temp = session('temp_password');
    $hasFilters = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/register.css') }}?v={{ @filemtime(public_path('css/register.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Users &amp; team</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        {{-- Temporary password: shown ONCE (flash). Refreshing the page removes it. --}}
        @if ($temp)
            <div class="card" style="border-color:#f1d78f;background:#fffbea" x-data="{ copied: false }">
                <h3 class="p6-card-t"><i class="fa-solid fa-key"></i> Temporary password for {{ $temp['name'] }}</h3>
                <p class="p6-card-s">
                    Give this to <b>{{ $temp['email'] }}</b>. It is shown <b>only once</b>: if you leave or refresh this page you must reset the password again.
                    They will be asked to choose their own password the first time they sign in.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <code style="font-size:20px;font-weight:700;letter-spacing:.06em;padding:8px 14px;background:#fff;border:1px dashed #d9b84a;border-radius:8px;user-select:all">{{ $temp['password'] }}</code>
                    <button type="button" class="btn btn-sm"
                            @click="try { navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($temp['password']) }}); copied = true } catch (e) {}">
                        <i class="fa-regular fa-copy"></i> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </button>
                </div>
            </div>
        @endif

        <p class="p6-note">
            Add people to the system, change their <b>role</b> or <b>department</b>, reset a forgotten password, or <b>deactivate</b> an account.
            A deactivated person cannot sign in, but their tickets and history stay. You cannot deactivate yourself, change your own role or reset your own password here.
            Every change is written to the <b>Audit log</b>.
        </p>

        {{-- Account approval: people who registered themselves and wait for a decision --}}
        @if ($pending->isNotEmpty())
            <div class="card" style="border-color:#f1d78f;background:#fffbea">
                <h3 class="p6-card-t">
                    <i class="fa-solid fa-user-clock"></i> Waiting for approval
                    <span class="badge nocap ab-file" style="margin-left:6px">{{ $pendingTotal }}</span>
                </h3>
                <p class="p6-card-s">
                    These people registered themselves and <b>cannot sign in yet</b>. Approve them to let them in (they start as Requesters; change
                    the role afterwards if needed), or reject them. They get an email with the result.
                    @if ($pendingTotal > $pending->count())The oldest {{ $pending->count() }} are shown.@endif
                </p>

                <div class="table-wrap">
                    <table class="stack">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Department</th>
                                <th>Registered</th>
                                <th style="text-align:right">Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $p)
                                <tr x-data="{ rejecting: false }">
                                    <td data-label="Person" style="word-break:break-word">
                                        <b>{{ $p->name }}</b>
                                        <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $p->email }}</div>
                                        @if ($p->requester_type)
                                            <div class="rt-badge">{{ config("registration.types.{$p->requester_type}.label", $p->requester_type) }}</div>
                                        @endif
                                        @if ($p->affiliation)<div class="rt-note"><b>Details:</b> {{ $p->affiliation }}</div>@endif
                                        @if ($p->reg_purpose)<div class="rt-note"><b>Why:</b> {{ $p->reg_purpose }}</div>@endif
                                    </td>
                                    <td data-label="Department">{{ $p->department?->name ?? '—' }}</td>
                                    <td data-label="Registered" style="white-space:nowrap">{{ $p->created_at?->format('M j, Y g:i A') }}</td>
                                    <td data-label="Decision" style="text-align:right">
                                        <form method="POST" action="{{ route('admin.users.approve', $p) }}" style="display:inline">
                                            @csrf
                                            <button class="btn btn-sm btn-ok"><i class="fa-solid fa-user-check"></i> Approve</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" @click="rejecting = !rejecting">
                                            <i class="fa-solid fa-user-xmark"></i> Reject
                                        </button>

                                        <form method="POST" action="{{ route('admin.users.reject', $p) }}" x-show="rejecting" x-cloak style="display:none;margin-top:10px;text-align:left"
                                              onsubmit="return confirm({{ \Illuminate\Support\Js::from("Reject the registration of {$p->name}?") }})">
                                            @csrf
                                            <div class="field" style="margin-bottom:8px">
                                                <label for="rr-{{ $p->id }}">Reason <span class="hint" style="display:inline">(optional, the person will see it)</span></label>
                                                <input id="rr-{{ $p->id }}" type="text" name="reason" maxlength="255" placeholder="e.g. Not a student or staff of LCCT">
                                            </div>
                                            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-user-xmark"></i> Reject registration</button>
                                            <button type="button" class="btn btn-sm" @click="rejecting = false">Cancel</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Add --}}
        <div class="card" x-data="{ open: {{ $adding ? 'true' : 'false' }} }">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <h3 class="p6-card-t" style="margin:0">Add a user</h3>
                <button type="button" class="btn btn-sm btn-primary" style="margin-left:auto" @click="open = !open">
                    <i class="fa-solid" :class="open ? 'fa-minus' : 'fa-user-plus'"></i> <span x-text="open ? 'Close' : 'New user'">New user</span>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.users.store') }}" x-show="open" x-cloak style="{{ $adding ? '' : 'display:none' }};margin-top:14px">
                @csrf
                <input type="hidden" name="_form" value="create">

                <div class="row2">
                    <div class="field">
                        <label for="u-name">Full name</label>
                        <input id="u-name" type="text" name="name" maxlength="255" required value="{{ $adding ? old('name') : '' }}">
                        @if ($adding) @error('name')<div class="field-err">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="field">
                        <label for="u-email">School email</label>
                        <input id="u-email" type="email" name="email" maxlength="255" required autocomplete="off"
                               placeholder="name@{{ config('school.registration.email_domain') }}" value="{{ $adding ? old('email') : '' }}">
                        @if ($adding) @error('email')<div class="field-err">{{ $message }}</div>@enderror @endif
                    </div>
                </div>

                <div class="row2">
                    <div class="field">
                        <label for="u-role">Role</label>
                        <select id="u-role" name="role_id" required>
                            <option value="">Choose a role…</option>
                            @foreach ($roles as $r)
                                <option value="{{ $r->id }}" @selected($adding && (string) old('role_id') === (string) $r->id)>{{ $r->name }}</option>
                            @endforeach
                        </select>
                        @if ($adding) @error('role_id')<div class="field-err">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="field">
                        <label for="u-dept">Department</label>
                        <select id="u-dept" name="department_id">
                            <option value="">No department</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected($adding && (string) old('department_id') === (string) $d->id) @disabled(! $d->is_active)>{{ $d->name }}@unless($d->is_active) (inactive) @endunless</option>
                            @endforeach
                        </select>
                        @if ($adding) @error('department_id')<div class="field-err">{{ $message }}</div>@enderror @endif
                    </div>
                </div>

                <div class="p6-hint">Department Staff take and do the work (they can only assign tickets to themselves). The Department Head also assigns anyone in the department, authorizes, closes and reads the audit log. Staff and Heads need a Department: their powers apply to that department only. The Maintenance Admin also manages users, settings and categories. The System Administrator works across all departments and needs no Department.</div>

                <div class="field">
                    <label for="u-emp">Employee no. <span class="hint" style="display:inline">(optional)</span></label>
                    <input id="u-emp" type="text" name="employee_no" maxlength="50" value="{{ $adding ? old('employee_no') : '' }}">
                    @if ($adding) @error('employee_no')<div class="field-err">{{ $message }}</div>@enderror @endif
                </div>

                <div class="p6-hint">A temporary password is made for you and shown once after saving. The person must change it at first sign-in.</div>

                <div class="p6-save">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Create account</button>
                </div>
            </form>
        </div>

        {{-- Search + filters --}}
        <form method="GET" action="{{ route('admin.users.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field wide">
                    <label for="f-q">Search</label>
                    <input id="f-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or employee no.">
                </div>

                <div class="field">
                    <label for="f-role">Role</label>
                    <select id="f-role" name="role">
                        <option value="">All roles</option>
                        @foreach ($roles as $r)
                            <option value="{{ $r->id }}" @selected((string) ($filters['role'] ?? '') === (string) $r->id)>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-dept">Department</label>
                    <select id="f-dept" name="department">
                        <option value="">All departments</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected((string) ($filters['department'] ?? '') === (string) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-status">Status</label>
                    <select id="f-status" name="status">
                        <option value="">Active and inactive</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                @if ($hasFilters)<a href="{{ route('admin.users.index') }}" class="btn">Clear</a>@endif
                <span class="push" style="font-size:12.5px;color:var(--text-3)">{{ $users->total() }} {{ \Illuminate\Support\Str::plural('user', $users->total()) }}</span>
            </div>
        </form>

        {{-- List --}}
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th style="width:110px">Status</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>

                    @forelse ($users as $u)
                        @php
                            $isMe = $u->id === $me;
                            $isEdit = (string) $editing === (string) $u->id;
                            $roleNow = $isEdit ? old('role_id') : $u->role_id;
                            $deptNow = $isEdit ? old('department_id') : $u->department_id;
                        @endphp

                        <tbody x-data="{ edit: {{ $isEdit ? 'true' : 'false' }} }">
                            <tr @if (! $u->is_active) style="opacity:.62" @endif>
                                <td data-label="User" style="word-break:break-word">
                                    <b>{{ $u->name }}</b>
                                    @if ($isMe)<span class="badge pr-normal nocap" style="margin-left:6px">You</span>@endif
                                    @if ($u->must_change_password)<span class="badge nocap ab-file" style="margin-left:6px" title="Has not chosen their own password yet"><i class="fa-solid fa-key"></i> Temp password</span>@endif
                                    <div class="sub" style="font-size:12.5px;color:var(--text-3)">
                                        {{ $u->email }}@if ($u->employee_no) · {{ $u->employee_no }}@endif
                                    </div>
                                </td>
                                <td data-label="Role">{{ $u->role?->name ?? '—' }}</td>
                                <td data-label="Department">{{ $u->department?->name ?? '—' }}</td>
                                <td data-label="Status">
                                    @if ($u->isRejected())
                                        <span class="badge nocap ab-fail" title="{{ $u->rejection_reason ?: 'Registration rejected' }}">Rejected</span>
                                    @elseif ($u->is_active)
                                        <span class="badge nocap ab-ok">Active</span>
                                    @else
                                        <span class="badge nocap ab-fail">Inactive</span>
                                    @endif
                                </td>
                                <td data-label="Actions" style="text-align:right;white-space:nowrap">
                                    <button type="button" class="btn btn-sm" @click="edit = !edit"><i class="fa-solid fa-pen"></i> Edit</button>

                                    @if (! $u->isApproved())
                                        {{-- rejected registration: the only thing to do is change your mind --}}
                                        <form method="POST" action="{{ route('admin.users.approve', $u) }}" style="display:inline">
                                            @csrf
                                            <button class="btn btn-sm btn-ok"><i class="fa-solid fa-user-check"></i> Approve</button>
                                        </form>
                                    @else
                                    @unless ($isMe)
                                        <form method="POST" action="{{ route('admin.users.reset-password', $u) }}" style="display:inline"
                                              onsubmit="return confirm({{ \Illuminate\Support\Js::from("Reset the password of {$u->name}? They will be signed out and must choose a new password.") }})">
                                            @csrf
                                            <button class="btn btn-sm" title="Make a new temporary password"><i class="fa-solid fa-key"></i> Reset password</button>
                                        </form>
                                    @endunless

                                    <form method="POST" action="{{ route('admin.users.status', $u) }}" style="display:inline"
                                          @if ($u->is_active) onsubmit="return confirm({{ \Illuminate\Support\Js::from("Deactivate {$u->name}? They will be signed out and can no longer sign in.") }})" @endif>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $u->is_active ? 0 : 1 }}">
                                        @if ($u->is_active)
                                            <button class="btn btn-sm btn-danger" @disabled($isMe) title="{{ $isMe ? 'You cannot deactivate yourself' : 'Deactivate' }}">
                                                <i class="fa-solid fa-user-slash"></i> Deactivate
                                            </button>
                                        @else
                                            <button class="btn btn-sm btn-ok"><i class="fa-solid fa-user-check"></i> Activate</button>
                                        @endif
                                    </form>
                                    @endif
                                </td>
                            </tr>

                            {{-- Edit row: role + department --}}
                            <tr x-show="edit" x-cloak style="display:none">
                                <td colspan="5" style="background:#f6f9fd">
                                    <form method="POST" action="{{ route('admin.users.update', $u) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="_edit_id" value="{{ $u->id }}">

                                        <div class="row2">
                                            <div class="field">
                                                <label for="r-{{ $u->id }}">Role</label>
                                                @if ($isMe)
                                                    <input type="hidden" name="role_id" value="{{ $u->role_id }}">
                                                    <input id="r-{{ $u->id }}" type="text" value="{{ $u->role?->name }}" readonly>
                                                    <div class="p6-hint">You cannot change your own role.</div>
                                                @else
                                                    <select id="r-{{ $u->id }}" name="role_id" required>
                                                        @foreach ($roles as $r)
                                                            <option value="{{ $r->id }}" @selected((string) $roleNow === (string) $r->id)>{{ $r->name }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                                @if ($isEdit) @error('role_id')<div class="field-err">{{ $message }}</div>@enderror @endif
                                            </div>

                                            <div class="field">
                                                <label for="d-{{ $u->id }}">Department</label>
                                                <select id="d-{{ $u->id }}" name="department_id">
                                                    <option value="">No department</option>
                                                    @foreach ($departments as $d)
                                                        <option value="{{ $d->id }}" @selected((string) $deptNow === (string) $d->id) @disabled(! $d->is_active && (string) $deptNow !== (string) $d->id)>{{ $d->name }}@unless($d->is_active) (inactive) @endunless</option>
                                                    @endforeach
                                                </select>
                                                @if ($isEdit) @error('department_id')<div class="field-err">{{ $message }}</div>@enderror @endif
                                            </div>
                                        </div>

                                        <div class="p6-save">
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                            <button type="button" class="btn btn-sm" @click="edit = false">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="5"><div class="empty"><i class="fa-solid fa-users"></i>No users match your search.</div></td></tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
        </div>

        @include('partials.pager', ['paginator' => $users])
    </div>
</x-app-layout>
