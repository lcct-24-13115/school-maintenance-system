@props(['name' => 'files'])

{{--
    Multi-file picker (phone friendly). Shows the chosen names + sizes, lets the person remove one before sending.
    "Take photo" opens the camera on phones; "Choose files" opens the gallery / file manager (several at once).
    Both add to ONE list; the hidden real input (name="files[]") is filled from that list on every change.
    Limits come from config/school.php -> attachments (the server checks everything again).
    Preview before sending: thumbnails for pictures/PDF (click opens the pop-up viewer), a badge for other files, and an X to remove.
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

    {{-- Preview before sending: picture thumbnail (click = pop-up viewer) or a file-type badge, name, size and an X to take it out. --}}
    <ul class="fp-list" x-show="files.length" x-cloak>
        <template x-for="(f, i) in files" :key="i + '-' + f.name + '-' + f.size + '-' + f.lastModified">
            <li class="fp-item">
                <template x-if="previews[i] && kinds[i]">
                    <a :href="previews[i]" class="fp-thumb" data-file-view
                       :data-kind="kinds[i]" :data-name="f.name" :data-src="previews[i]"
                       :data-meta="human(f.size) + ' · not sent yet'" :aria-label="'Preview ' + f.name">
                        <img x-show="kinds[i] === 'image'" :src="previews[i]" alt="">
                        <span x-show="kinds[i] === 'pdf'" class="fp-badge" style="background:#fdecec;color:#b42318">PDF</span>
                    </a>
                </template>
                <template x-if="!(previews[i] && kinds[i])">
                    <span class="fp-thumb fp-thumb-static" aria-hidden="true"><span class="fp-badge" x-text="ext(f.name)"></span></span>
                </template>
                <div class="fp-meta">
                    <div class="fp-name" x-text="f.name"></div>
                    <div class="hint" style="margin:0" x-text="human(f.size)"></div>
                </div>
                <button type="button" @click="remove(i)" class="fp-x" :aria-label="'Remove ' + f.name" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </li>
        </template>
    </ul>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('filePicker', (cfg) => ({
                files: [],
                previews: [],   // object URL per file (pictures and PDFs only), same order as files
                kinds: [],      // 'image' | 'pdf' | ''
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
                        const e = this.ext(f.name), kind = /^(jpe?g|png|webp)$/.test(e) ? 'image' : (e === 'pdf' ? 'pdf' : '');
                        this.kinds.push(kind);
                        this.previews.push(kind ? URL.createObjectURL(f) : '');
                    }
                    event.target.value = '';   // allow picking the same file again after removing it
                    this.sync();
                },
                remove(i) {
                    if (this.previews[i]) { URL.revokeObjectURL(this.previews[i]); }
                    this.files.splice(i, 1);
                    this.previews.splice(i, 1);
                    this.kinds.splice(i, 1);
                    this.notes = [];
                    this.sync();
                },
                sync() {
                    const dt = new DataTransfer();
                    this.files.forEach(f => dt.items.add(f));
                    this.$refs.final.files = dt.files;
                },
                ext(name) {
                    const i = name.lastIndexOf('.');
                    return i < 0 ? '' : name.slice(i + 1).toLowerCase();
                },
                human(bytes) {
                    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
                },
            }));
        });
    </script>
@endonce

@once
    <style>
        .fp-list{list-style:none;margin:10px 0 0;padding:0}
        .fp-item{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line)}
        .fp-item:last-child{border-bottom:0}
        .fp-thumb{flex:none;width:56px;height:56px;border-radius:12px;overflow:hidden;display:grid;place-items:center;background:var(--mist,#f1f6fc);border:1px solid var(--line);text-decoration:none}
        a.fp-thumb{cursor:zoom-in}
        a.fp-thumb:focus-visible{outline:3px solid var(--blue);outline-offset:2px}
        .fp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
        .fp-badge{font-size:11px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:var(--deep,#1b3355);padding:3px 6px;border-radius:6px}
        .fp-meta{min-width:0;flex:1}
        .fp-name{font-weight:700;font-size:14px;color:var(--navy);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .fp-x{flex:none;width:38px;height:38px;border-radius:50%;border:1.5px solid var(--line);background:#fff;color:#b42318;cursor:pointer;display:grid;place-items:center;font-size:15px}
        .fp-x:hover{background:#fdecec;border-color:#f3b7b2}
        .fp-x:focus-visible{outline:3px solid var(--blue);outline-offset:2px}
        [x-cloak]{display:none!important}
    </style>
    {{-- the same pop-up viewer as the ticket page (rendered once per page) --}}
    @push('scripts')
        @include('partials.file-viewer')
    @endpush
@endonce
