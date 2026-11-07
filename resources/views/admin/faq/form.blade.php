@php
    $editing = $faq->exists;

    // After a failed save the page comes back with the old input; otherwise show the saved values.
    $hasOld = count(session()->getOldInput()) > 0;

    $topicNow = $hasOld ? (string) old('topic', '') : (string) $faq->topic;
    $newTopicNow = $hasOld ? (string) old('new_topic', '') : '';
    $everyoneNow = $hasOld ? (bool) old('everyone') : $faq->isForEveryone();
    $rolesNow = $hasOld ? array_map('strval', (array) old('audience', [])) : ($faq->isForEveryone() ? [] : $faq->audienceList());
    $publicNow = $hasOld ? (bool) old('is_public') : (bool) $faq->is_public;
    $publishedNow = $hasOld ? (bool) old('is_published') : (bool) $faq->is_published;
    $orderNow = $hasOld ? old('sort_order') : ($editing ? $faq->sort_order : null);

    $action = $editing ? route('admin.faq.update', $faq) : route('admin.faq.store');
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/help.css') }}?v={{ @filemtime(public_path('css/help.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">{{ $editing ? 'Edit FAQ' : 'Add FAQ' }}</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif

        @if ($errors->any())
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>Please fix the highlighted fields and save again.</span></div>
        @endif

        <p class="p6-bar" style="margin:0 0 14px"><a href="{{ route('admin.faq.index') }}" class="btn"><i class="fa-solid fa-arrow-left"></i> Back to the list</a></p>

        <form method="POST" action="{{ $action }}" class="card" id="faq-form">
            @csrf
            @if ($editing) @method('PUT') @endif

            <h3 class="p6-card-t">{{ $editing ? 'Edit this entry' : 'Write a new entry' }}</h3>

            {{-- Topic --}}
            <div class="row2">
                <div class="field">
                    <label for="topic">Topic</label>
                    <select id="topic" name="topic">
                        <option value="">Choose a topic</option>
                        @foreach ($topics as $t)
                            <option value="{{ $t }}" @selected($topicNow === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                    @error('topic')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="new_topic">Or a new topic <span class="hint" style="display:inline">(used instead of the list when filled in)</span></label>
                    <input id="new_topic" type="text" name="new_topic" maxlength="60" value="{{ $newTopicNow }}" placeholder="e.g. Using the QR codes">
                </div>
            </div>

            {{-- Question --}}
            <div class="field">
                <label for="question">Question</label>
                <input id="question" type="text" name="question" minlength="5" maxlength="255" required value="{{ $hasOld ? old('question') : $faq->question }}">
                @error('question')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            {{-- Answer + live preview --}}
            <div class="row2">
                <div class="field">
                    <label for="answer">Answer</label>
                    <textarea id="answer" name="answer" rows="10" minlength="5" maxlength="5000" required>{{ $hasOld ? old('answer') : $faq->answer }}</textarea>
                    <div class="p6-hint">
                        Formatting: start a line with <code>- </code> for bullets, <code>1. </code> for steps, put <code>**bold**</code> around words.
                        A blank line starts a new paragraph. Link to a page of this site with <code>[label](/help)</code>.
                    </div>
                    @error('answer')<div class="field-err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label>Preview <span class="hint" style="display:inline">(how it looks on the Help page)</span></label>
                    <div class="help-answer" id="faq-preview" style="min-height:120px;border:1.5px dashed var(--line);border-radius:16px;padding:12px 16px;background:#fff"></div>
                </div>
            </div>

            {{-- Audience --}}
            <div class="field">
                <label>Who can read it</label>
                <label class="p6-check" style="margin-bottom:10px">
                    <input type="checkbox" name="everyone" id="everyone" value="1" @checked($everyoneNow)>
                    <span>Everyone<small>Every signed-in role sees it.</small></span>
                </label>

                <div style="display:flex;flex-wrap:wrap;gap:10px" id="role-boxes">
                    @foreach ($roleLabels as $slug => $label)
                        <label class="p6-check" style="flex:1 1 200px">
                            <input type="checkbox" name="audience[]" value="{{ $slug }}" class="role-box" @checked(in_array($slug, $rolesNow, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('audience')<div class="field-err">{{ $message }}</div>@enderror
                @error('audience.*')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            {{-- Visibility --}}
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <label class="p6-check" style="flex:1 1 260px">
                    <input type="checkbox" name="is_public" id="is_public" value="1" @checked($publicNow)>
                    <span>Show before sign-in<small>Visitors who are not signed in can read it. Only when the audience is Everyone.</small></span>
                </label>
                <label class="p6-check" style="flex:1 1 260px">
                    <input type="checkbox" name="is_published" value="1" @checked($publishedNow)>
                    <span>Published<small>Untick to keep it hidden from the Help page.</small></span>
                </label>
            </div>
            @error('is_public')<div class="field-err">{{ $message }}</div>@enderror
            @error('is_published')<div class="field-err">{{ $message }}</div>@enderror

            {{-- Order --}}
            <div class="field" style="margin-top:14px;max-width:260px">
                <label for="sort_order">Sort order</label>
                <input id="sort_order" type="number" name="sort_order" min="0" max="65535" value="{{ $orderNow }}"
                       placeholder="{{ $editing ? 'Keep the current order' : 'Empty = end of the topic' }}">
                <div class="p6-hint">Smaller numbers come first inside a topic. You can also use the arrows on the list.</div>
                @error('sort_order')<div class="field-err">{{ $message }}</div>@enderror
            </div>

            <div class="p6-save">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> {{ $editing ? 'Save changes' : 'Add entry' }}</button>
                <a href="{{ route('admin.faq.index') }}" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var everyone = document.getElementById('everyone');
            var isPublic = document.getElementById('is_public');
            var roles = document.querySelectorAll('.role-box');
            var answer = document.getElementById('answer');
            var preview = document.getElementById('faq-preview');

            // "Everyone" switches the role boxes off; "Show before sign-in" only works with Everyone.
            function syncAudience() {
                var all = everyone.checked;
                roles.forEach(function (box) {
                    if (all) { box.checked = false; }
                    box.disabled = all;
                });
                if (!all) { isPublic.checked = false; }
                isPublic.disabled = !all;
            }
            everyone.addEventListener('change', syncAudience);
            roles.forEach(function (box) {
                box.addEventListener('change', function () {
                    if (box.checked) { everyone.checked = false; syncAudience(); }
                });
            });
            syncAudience();

            // Preview: same rules as App\Support\FaqText (escape first, then only these marks).
            function esc(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }
            function inline(text) {
                var s = esc(text);
                s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                s = s.replace(/\[([^\]]+)\]\((\/(?!\/)[A-Za-z0-9_\-\/\.\?=&;#%]*)\)/g, '<a href="$2">$1</a>');
                return s;
            }
            function render(text) {
                var lines = text.trim().split(/\r\n|\r|\n/);
                var out = [], para = [], list = null;
                function closePara() { if (para.length) { out.push('<p>' + para.join('<br>') + '</p>'); para = []; } }
                function closeList() { if (list) { out.push('</' + list + '>'); list = null; } }
                lines.forEach(function (raw) {
                    var line = raw.trim(), m;
                    if (line === '') { closePara(); closeList(); return; }
                    if ((m = line.match(/^-\s+(.+)$/))) {
                        closePara();
                        if (list !== 'ul') { closeList(); out.push('<ul>'); list = 'ul'; }
                        out.push('<li>' + inline(m[1]) + '</li>');
                        return;
                    }
                    if ((m = line.match(/^\d+[.)]\s+(.+)$/))) {
                        closePara();
                        if (list !== 'ol') { closeList(); out.push('<ol>'); list = 'ol'; }
                        out.push('<li>' + inline(m[1]) + '</li>');
                        return;
                    }
                    closeList();
                    para.push(inline(line));
                });
                closePara(); closeList();
                return out.join('\n');
            }
            function update() {
                preview.innerHTML = answer.value.trim() === ''
                    ? '<p style="color:#8a97ab">Start typing the answer to see it here.</p>'
                    : render(answer.value);
            }
            // links in the preview must not take the editor away from the form
            preview.addEventListener('click', function (e) { if (e.target.closest('a')) { e.preventDefault(); } });
            answer.addEventListener('input', update);
            update();
        })();
    </script>
</x-app-layout>