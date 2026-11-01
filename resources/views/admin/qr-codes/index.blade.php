@php
    // After a failed save the page comes back with the old input; _edit_id says which card it was (blank = the "make" form).
    $editing = old('_edit_id');
    $adding = blank($editing);
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/phase17.css') }}?v={{ @filemtime(public_path('css/phase17.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">QR codes</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        @if ($baseIsLocal)
            <div class="alert warn" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>
                    The site address in <b>.env</b> (<code>APP_URL</code>) is <b>{{ $baseUrl ?: 'empty' }}</b>.
                    A phone cannot open that address, so a QR code made now would scan but not load.
                    Set <code>APP_URL</code> to the address people really use (the school's domain, or this computer's network address),
                    then run <code>php artisan config:clear</code> before you print.
                </span>
            </div>
        @endif

        <p class="p6-note">
            Make a QR code, print it, and put it where people need it. The <b>Login</b>, <b>Register</b> and <b>New repair request</b> codes
            open <b>{{ $baseUrl ?: 'your site' }}</b>. A <b>custom link</b> opens any web address you type. Choose a <b>dark colour</b>:
            the background is always white so phones can read it.
        </p>

        {{-- Make --}}
        <form method="POST" action="{{ route('admin.qr-codes.store') }}" class="card"
              x-data="{ kind: {{ \Illuminate\Support\Js::from($adding ? old('kind', 'login') : 'login') }} }">
            @csrf
            <h3 class="p6-card-t">Make a QR code</h3>

            <div class="row2">
                <div class="field">
                    <label for="new-title">Title <span class="hint" style="display:inline">(printed above the code)</span></label>
                    <input id="new-title" type="text" name="title" maxlength="100" required value="{{ $adding ? old('title') : '' }}">
                    @if ($adding) @error('title')<div class="field-err">{{ $message }}</div>@enderror @endif
                </div>

                <div class="field">
                    <label for="new-kind">Opens</label>
                    <select id="new-kind" name="kind" required x-model="kind">
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}" @disabled($value === 'register' && ! $registrationOpen)>
                                {{ $label }}@if ($value === 'register' && ! $registrationOpen) (registration is closed)@endif
                            </option>
                        @endforeach
                    </select>
                    @if ($adding) @error('kind')<div class="field-err">{{ $message }}</div>@enderror @endif
                </div>
            </div>

            <div class="field" x-show="kind === 'custom'" x-cloak>
                <label for="new-url">Link</label>
                <input id="new-url" type="url" name="custom_url" maxlength="2000" placeholder="https://" value="{{ $adding ? old('custom_url') : '' }}" :required="kind === 'custom'">
                @if ($adding) @error('custom_url')<div class="field-err">{{ $message }}</div>@enderror @endif
            </div>

            <div class="field">
                <label for="new-color">Colour</label>
                <input id="new-color" class="q17-color" type="color" name="color" value="{{ $adding ? old('color', $defaultColor) : $defaultColor }}">
                @if ($adding) @error('color')<div class="field-err">{{ $message }}</div>@enderror @endif
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-qrcode"></i> Make QR code</button>
            </div>
        </form>

        {{-- The codes --}}
        @if ($codes->isEmpty())
            <section class="card">
                <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-qrcode"></i>No QR codes yet. Make the first one above.</div>
            </section>
        @else
            <div class="q17-grid">
                @foreach ($codes as $qr)
                    @php
                        $isEdit = (string) $editing === (string) $qr->id;
                        $kindNow = $isEdit ? old('kind', $qr->kind) : $qr->kind;
                        $confirm = "Delete the QR code \"{$qr->title}\"? Printed copies will still scan, but you can no longer print it from here.";
                    @endphp

                    <section class="card q17-card" x-data="{ edit: {{ $isEdit ? 'true' : 'false' }}, kind: {{ \Illuminate\Support\Js::from($kindNow) }} }">
                        <h3 class="q17-title">{{ $qr->title }}</h3>
                        <div class="q17-meta">
                            <span class="badge pr-normal nocap">{{ $qr->kind_label }}</span>
                        </div>

                        <div class="q17-box" id="qr-{{ $qr->id }}" data-qr="{{ $qr->url }}" data-qr-color="{{ $qr->color }}" data-qr-label="QR code: {{ $qr->title }}"></div>

                        <div class="q17-url">{{ $qr->url }}</div>

                        <div class="q17-actions">
                            <a class="btn btn-sm" href="{{ route('admin.qr-codes.print', $qr) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print</a>
                            <button type="button" class="btn btn-sm" data-qr-download="qr-{{ $qr->id }}" data-filename="{{ \Illuminate\Support\Str::slug($qr->title) ?: 'qr-code' }}"><i class="fa-solid fa-download"></i> SVG</button>
                            <button type="button" class="btn btn-sm" @click="edit = !edit"><i class="fa-solid fa-pen"></i> Edit</button>
                            <form method="POST" action="{{ route('admin.qr-codes.destroy', $qr) }}" style="display:inline"
                                  onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }})">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>

                        <form method="POST" action="{{ route('admin.qr-codes.update', $qr) }}" x-show="edit" x-cloak class="q17-edit">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="_edit_id" value="{{ $qr->id }}">

                            <div class="field">
                                <label for="t-{{ $qr->id }}">Title</label>
                                <input id="t-{{ $qr->id }}" type="text" name="title" maxlength="100" required value="{{ $isEdit ? old('title') : $qr->title }}">
                                @if ($isEdit) @error('title')<div class="field-err">{{ $message }}</div>@enderror @endif
                            </div>

                            <div class="field">
                                <label for="k-{{ $qr->id }}">Opens</label>
                                <select id="k-{{ $qr->id }}" name="kind" required x-model="kind">
                                    @foreach ($kinds as $value => $label)
                                        <option value="{{ $value }}" @disabled($value === 'register' && ! $registrationOpen && $qr->kind !== 'register')>
                                            {{ $label }}@if ($value === 'register' && ! $registrationOpen) (registration is closed)@endif
                                        </option>
                                    @endforeach
                                </select>
                                @if ($isEdit) @error('kind')<div class="field-err">{{ $message }}</div>@enderror @endif
                            </div>

                            <div class="field" x-show="kind === 'custom'" x-cloak>
                                <label for="u-{{ $qr->id }}">Link</label>
                                <input id="u-{{ $qr->id }}" type="url" name="custom_url" maxlength="2000" placeholder="https://" value="{{ $isEdit ? old('custom_url') : $qr->custom_url }}" :required="kind === 'custom'">
                                @if ($isEdit) @error('custom_url')<div class="field-err">{{ $message }}</div>@enderror @endif
                            </div>

                            <div class="field">
                                <label for="c-{{ $qr->id }}">Colour</label>
                                <input id="c-{{ $qr->id }}" class="q17-color" type="color" name="color" value="{{ $isEdit ? old('color', $qr->color) : $qr->color }}">
                                @if ($isEdit) @error('color')<div class="field-err">{{ $message }}</div>@enderror @endif
                            </div>

                            <div class="p6-save">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Save</button>
                                <button type="button" class="btn btn-sm" @click="edit = false">Cancel</button>
                            </div>
                        </form>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
