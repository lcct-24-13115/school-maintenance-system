@php
    // After a failed save the page comes back with the old input; _edit_id says which row it was.
    $editing = old('_edit_id');
    $adding = blank($editing);
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Categories</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <p class="p6-note">
            These are the choices in the <b>Maintenance category</b> list of the repair form.
            <b>Hide</b> a category to stop offering it on new tickets (old tickets keep it).
            <b>Locked</b> categories cannot be deleted, renamed or retyped.
            <b>Deleting</b> a category moves its tickets to <b>Other</b> of the same type.
        </p>

        {{-- Add --}}
        <form method="POST" action="{{ route('admin.categories.store') }}" class="card">
            @csrf
            <h3 class="p6-card-t">Add a category</h3>

            <div class="row2">
                <div class="field">
                    <label for="new-name">Name</label>
                    <input id="new-name" type="text" name="name" maxlength="100" required value="{{ $adding ? old('name') : '' }}">
                    @if ($adding) @error('name')<div class="field-err">{{ $message }}</div>@enderror @endif
                </div>

                <div class="field">
                    <label for="new-type">Type</label>
                    <select id="new-type" name="type" required>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($adding && old('type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($adding) @error('type')<div class="field-err">{{ $message }}</div>@enderror @endif
                </div>
            </div>

            <div class="field">
                <label for="new-desc">Description <span class="hint" style="display:inline">(optional)</span></label>
                <input id="new-desc" type="text" name="description" maxlength="500" value="{{ $adding ? old('description') : '' }}">
                @if ($adding) @error('description')<div class="field-err">{{ $message }}</div>@enderror @endif
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <label class="p6-check" style="flex:1 1 220px">
                    <input type="checkbox" name="is_hidden" value="1" @checked($adding && old('is_hidden'))>
                    <span>Start hidden<small>Not offered on new tickets yet.</small></span>
                </label>
                <label class="p6-check" style="flex:1 1 220px">
                    <input type="checkbox" name="is_locked" value="1" @checked($adding && old('is_locked'))>
                    <span>Locked<small>Cannot be deleted, renamed or retyped.</small></span>
                </label>
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add category</button>
            </div>
        </form>

        {{-- One card per type --}}
        @foreach ($types as $type => $typeLabel)
            @php $list = $categories->get($type, collect()); @endphp

            <section class="card">
                <div class="card-h"><h3>{{ $typeLabel }} <span class="hint" style="font-family:var(--font-body);font-weight:400">({{ $list->count() }})</span></h3></div>

                @if ($list->isEmpty())
                    <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-tags"></i>No {{ strtolower($typeLabel) }} categories yet.</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:96px">Order</th>
                                    <th>Category</th>
                                    <th style="width:90px">Tickets</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>

                            @foreach ($list as $c)
                                @php
                                    $isEdit = (string) $editing === (string) $c->id;
                                    $hiddenNow = $isEdit ? (bool) old('is_hidden') : $c->is_hidden;
                                    $lockedNow = $isEdit ? (bool) old('is_locked') : $c->is_locked;
                                    $confirm = $c->requests_count > 0
                                        ? "Delete \"{$c->name}\"? Its {$c->requests_count} ticket(s) will move to Other."
                                        : "Delete \"{$c->name}\"?";
                                @endphp

                                <tbody x-data="{ edit: {{ $isEdit ? 'true' : 'false' }} }">
                                    <tr @if ($c->is_hidden) style="opacity:.62" @endif>
                                        <td>
                                            <form method="POST" action="{{ route('admin.categories.move', $c) }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="direction" value="up">
                                                <button class="btn btn-sm" style="padding:0 11px" title="Move up" aria-label="Move up" @disabled($loop->first)><i class="fa-solid fa-arrow-up"></i></button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.categories.move', $c) }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="direction" value="down">
                                                <button class="btn btn-sm" style="padding:0 11px" title="Move down" aria-label="Move down" @disabled($loop->last)><i class="fa-solid fa-arrow-down"></i></button>
                                            </form>
                                        </td>

                                        <td style="word-break:break-word">
                                            <b>{{ $c->name }}</b>
                                            @if ($c->is_locked)<span class="badge pr-normal nocap" style="margin-left:6px"><i class="fa-solid fa-lock"></i> Locked</span>@endif
                                            @if ($c->is_hidden)<span class="badge pr-normal nocap" style="margin-left:6px"><i class="fa-solid fa-eye-slash"></i> Hidden</span>@endif
                                            @if ($c->description)<div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $c->description }}</div>@endif
                                        </td>

                                        <td class="nums">{{ $c->requests_count }}</td>

                                        <td style="text-align:right;white-space:nowrap">
                                            <form method="POST" action="{{ route('admin.categories.toggle', $c) }}" style="display:inline">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-sm" title="{{ $c->is_hidden ? 'Show on new tickets' : 'Hide from new tickets' }}">
                                                    <i class="fa-solid {{ $c->is_hidden ? 'fa-eye' : 'fa-eye-slash' }}"></i> {{ $c->is_hidden ? 'Show' : 'Hide' }}
                                                </button>
                                            </form>

                                            <button type="button" class="btn btn-sm" @click="edit = !edit"><i class="fa-solid fa-pen"></i> Edit</button>

                                            <form method="POST" action="{{ route('admin.categories.destroy', $c) }}" style="display:inline"
                                                  onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }})">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-danger" @disabled($c->is_locked)
                                                        title="{{ $c->is_locked ? 'Locked: unlock it first' : 'Delete' }}"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>

                                    {{-- Edit row --}}
                                    <tr x-show="edit" x-cloak style="display:none">
                                        <td colspan="4" style="background:#f6f9fd">
                                            <form method="POST" action="{{ route('admin.categories.update', $c) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="_edit_id" value="{{ $c->id }}">

                                                <div class="row2">
                                                    <div class="field">
                                                        <label for="n-{{ $c->id }}">Name</label>
                                                        <input id="n-{{ $c->id }}" type="text" name="name" maxlength="100" required
                                                               value="{{ $isEdit ? old('name') : $c->name }}" @readonly($c->is_locked)>
                                                        @if ($isEdit) @error('name')<div class="field-err">{{ $message }}</div>@enderror @endif
                                                    </div>

                                                    <div class="field">
                                                        <label for="t-{{ $c->id }}">Type</label>
                                                        @if ($c->is_locked)
                                                            <input type="hidden" name="type" value="{{ $c->type }}">
                                                            <input id="t-{{ $c->id }}" type="text" value="{{ $typeLabel }}" readonly>
                                                        @else
                                                            <select id="t-{{ $c->id }}" name="type" required>
                                                                @foreach ($types as $value => $label)
                                                                    <option value="{{ $value }}" @selected(($isEdit ? old('type') : $c->type) === $value)>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        @endif
                                                        @if ($isEdit) @error('type')<div class="field-err">{{ $message }}</div>@enderror @endif
                                                    </div>
                                                </div>

                                                <div class="field">
                                                    <label for="d-{{ $c->id }}">Description</label>
                                                    <input id="d-{{ $c->id }}" type="text" name="description" maxlength="500" value="{{ $isEdit ? old('description') : $c->description }}">
                                                    @if ($isEdit) @error('description')<div class="field-err">{{ $message }}</div>@enderror @endif
                                                </div>

                                                <div style="display:flex;flex-wrap:wrap;gap:10px">
                                                    <label class="p6-check" style="flex:1 1 220px">
                                                        <input type="checkbox" name="is_hidden" value="1" @checked($hiddenNow)>
                                                        <span>Hidden<small>Not offered on new tickets.</small></span>
                                                    </label>
                                                    <label class="p6-check" style="flex:1 1 220px">
                                                        <input type="checkbox" name="is_locked" value="1" @checked($lockedNow)>
                                                        <span>Locked<small>Cannot be deleted, renamed or retyped.</small></span>
                                                    </label>
                                                </div>

                                                <div class="p6-save">
                                                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                                    <button type="button" class="btn btn-sm" @click="edit = false">Cancel</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</x-app-layout>
