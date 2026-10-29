@props(['name' => 'files'])

{{--
    Multi-file picker (phone friendly). Shows the chosen names + sizes, lets the person remove one before sending.
    "Take photo" opens the camera on phones; "Choose files" opens the gallery / file manager (several at once).
    Both add to ONE list; the hidden real input (name="files[]") is filled from that list on every change.
    Limits come from config/school.php -> attachments (the server checks everything again).
    Phase 5 (design): LCCT classes (.btn, .row2, .alert, .pf-row). The script below is unchanged.
--}}
@php
    $max = \App\Services\AttachmentService::maxFiles();
    $maxBytes = \App\Services\AttachmentService::maxBytes();
    // the two helper inputs stay in the page (so .click() works) but out of sight
    $vh = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;padding:0;opacity:0';
@endphp

<div x-data="filePicker({ max: {{ $max }}, maxBytes: {{ $maxBytes }}, maxLabel: @js(\App\Services\AttachmentService::maxSizeLabel()) })" {{ $attributes->merge(['style' => 'margin-top:6px']) }}>
    <input type="file" x-ref="final" name="{{ $name }}[]" multiple style="display:none" tabindex="-1" aria-hidden="true">
    <input type="file" x-ref="pick" multiple accept="{{ \App\Services\AttachmentService::accept() }}" style="{{ $vh }}" tabindex="-1" @change="add($event)">
    <input type="file" x-ref="camera" accept="image/*" capture="environment" style="{{ $vh }}" tabindex="-1" @change="add($event)">

    <div class="row2">
        <button type="button" @click="$refs.camera.click()" :disabled="files.length >= max"
                class="btn" style="width:100%;height:48px;border-color:var(--blue);color:var(--deep)"
                :style="files.length >= max ? 'opacity:.4;cursor:not-allowed' : ''">
            <i class="fa-solid fa-camera"></i> Take photo
        </button>
        <button type="button" @click="$refs.pick.click()" :disabled="files.length >= max"
                class="btn" style="width:100%;height:48px;border-color:var(--blue);color:var(--deep)"
                :style="files.length >= max ? 'opacity:.4;cursor:not-allowed' : ''">
            <i class="fa-solid fa-paperclip"></i> Choose files
        </button>
    </div>

    <p class="hint">
        Up to {{ $max }} files, {{ \App\Services\AttachmentService::maxSizeLabel() }} each.
        Allowed: {{ \App\Services\AttachmentService::allowedLabel() }}.
    </p>

    <template x-if="notes.length">
        <div class="alert err" role="alert" style="margin:10px 0 0">
            <i class="fa-solid fa-circle-exclamation"></i>
            <ul style="margin:0;padding-left:16px">
                <template x-for="(note, n) in notes" :key="n"><li x-text="note"></li></template>
            </ul>
        </div>
    </template>

    <ul style="list-style:none;margin:10px 0 0;padding:0" x-show="files.length">
        <template x-for="(f, i) in files" :key="i + '-' + f.name + '-' + f.size">
            <li class="pf-row" style="padding:9px 0">
                <div style="min-width:0">
                    <div style="font-weight:700;font-size:14px;color:var(--navy);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="f.name"></div>
                    <div class="hint" style="margin:0" x-text="human(f.size)"></div>
                </div>
                <button type="button" @click="remove(i)" class="btn btn-sm btn-danger" :aria-label="'Remove ' + f.name"><i class="fa-solid fa-xmark"></i> Remove</button>
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
