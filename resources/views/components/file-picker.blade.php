@props(['name' => 'files'])

{{--
    Multi-file picker (phone friendly). Shows the chosen names + sizes, lets the person remove one before sending.
    "Take photo" opens the camera on phones; "Choose files" opens the gallery / file manager (several at once).
    Both add to ONE list; the hidden real input (name="files[]") is filled from that list on every change.
    Limits come from config/school.php -> attachments (the server checks everything again).
--}}
@php
    $max = \App\Services\AttachmentService::maxFiles();
    $maxBytes = \App\Services\AttachmentService::maxBytes();
@endphp

<div x-data="filePicker({ max: {{ $max }}, maxBytes: {{ $maxBytes }}, maxLabel: @js(\App\Services\AttachmentService::maxSizeLabel()) })" {{ $attributes->merge(['class' => 'mt-1']) }}>
    <input type="file" x-ref="final" name="{{ $name }}[]" multiple class="hidden" tabindex="-1" aria-hidden="true">
    <input type="file" x-ref="pick" multiple accept="{{ \App\Services\AttachmentService::accept() }}" class="sr-only" tabindex="-1" @change="add($event)">
    <input type="file" x-ref="camera" accept="image/*" capture="environment" class="sr-only" tabindex="-1" @change="add($event)">

    <div class="grid grid-cols-2 gap-3">
        <button type="button" @click="$refs.camera.click()" :disabled="files.length >= max"
                class="min-h-[48px] rounded-xl border-2 border-blue-600 px-3 py-3 text-base font-bold text-blue-700 disabled:opacity-40">
            📷 Take photo
        </button>
        <button type="button" @click="$refs.pick.click()" :disabled="files.length >= max"
                class="min-h-[48px] rounded-xl border-2 border-blue-600 px-3 py-3 text-base font-bold text-blue-700 disabled:opacity-40">
            📎 Choose files
        </button>
    </div>

    <p class="mt-2 text-xs text-gray-500">
        Up to {{ $max }} files, {{ \App\Services\AttachmentService::maxSizeLabel() }} each.
        Allowed: {{ \App\Services\AttachmentService::allowedLabel() }}.
    </p>

    <template x-if="notes.length">
        <ul class="mt-2 space-y-1 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            <template x-for="(note, n) in notes" :key="n"><li x-text="note"></li></template>
        </ul>
    </template>

    <ul class="mt-3 space-y-2" x-show="files.length">
        <template x-for="(f, i) in files" :key="i + '-' + f.name + '-' + f.size">
            <li class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-gray-800" x-text="f.name"></div>
                    <div class="text-xs text-gray-500" x-text="human(f.size)"></div>
                </div>
                <button type="button" @click="remove(i)" class="min-h-[44px] min-w-[44px] shrink-0 rounded-lg px-3 text-sm font-bold text-red-600 hover:bg-red-50" :aria-label="'Remove ' + f.name">✕ Remove</button>
            </li>
        </template>
    </ul>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('filePicker', (cfg) => ({
                files: [],
                notes: [],
                max: cfg.max,
                add(event) {
                    this.notes = [];
                    for (const f of Array.from(event.target.files)) {
                        if (this.files.length >= cfg.max) {
                            this.notes.push('Only ' + cfg.max + ' files can be attached. "' + f.name + '" was not added.');
                            continue;
                        }
                        if (f.size > cfg.maxBytes) {
                            this.notes.push('"' + f.name + '" is ' + this.human(f.size) + '. The maximum is ' + cfg.maxLabel + ' per file.');
                            continue;
                        }
                        if (this.files.some(x => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified)) {
                            continue;   // same file picked twice
                        }
                        this.files.push(f);
                    }
                    event.target.value = '';   // allow picking the same file again after removing it
                    this.sync();
                },
                remove(i) {
                    this.files.splice(i, 1);
                    this.notes = [];
                    this.sync();
                },
                sync() {
                    const dt = new DataTransfer();
                    this.files.forEach(f => dt.items.add(f));
                    this.$refs.final.files = dt.files;
                },
                human(bytes) {
                    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
                },
            }));
        });
    </script>
@endonce
