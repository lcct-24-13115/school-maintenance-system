@php
    $action = $isGuest ? route('help.public') : route('help.index');
    // Builds a link back to this page with the given ?q= / ?topic= (empty values are left out).
    $url = function (array $params) use ($action) {
        $qs = http_build_query(array_filter($params, fn ($v) => $v !== null && $v !== ''));

        return $action.($qs !== '' ? '?'.$qs : '');
    };
    $count = $entries->count();
    $openAll = $q !== '' && $count > 0 && $count <= 3;   // a few matches: show the answers already open
@endphp

<link rel="stylesheet" href="{{ asset('css/help.css') }}?v={{ @filemtime(public_path('css/help.css')) }}">

<div class="help-page" id="help-top" tabindex="-1">
    <p class="help-print-head"><b>LCCT Maintenance - Help &amp; FAQ</b> &middot; printed {{ now()->format('F j, Y') }}@if ($topic) &middot; Topic: {{ $topic }}@endif @if ($q !== '') &middot; Search: {{ $q }}@endif</p>
    @if ($isGuest)
        <p class="help-back"><a href="{{ route('login') }}"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a></p>
        <h1 class="form-title">Help <span>&amp; FAQ</span></h1>
        <p class="help-intro">Answers for everyone. <a href="{{ route('login') }}">Sign in</a> to see the answers made for your role.</p>
    @else
        <p class="help-intro">Quick answers about sending and following repair requests. Pick a topic or type a few words.</p>
        @if (auth()->user()?->canManageFaq())
            <p class="help-manage" style="margin:0 0 14px">
                <a class="btn btn-primary btn-sm" href="{{ route('admin.faq.index') }}"><i class="fa-solid fa-pen-to-square"></i> Manage FAQ</a>
            </p>
        @endif
    @endif

    {{-- Works without JavaScript: a plain GET form. The instant filter (Phase 2E) only adds to it. --}}
    <form class="help-search" id="help-search-form" method="GET" action="{{ $action }}" role="search">
        <div class="help-search-box">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input id="help-q" type="search" name="q" value="{{ $q }}" maxlength="100" autocomplete="off"
                placeholder="Search the questions" aria-label="Search the questions">
        </div>
        @if ($topic)
            <input type="hidden" name="topic" value="{{ $topic }}">
        @endif
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        @if ($q !== '')
            <a class="btn" href="{{ $url(['topic' => $topic]) }}">Clear</a>
        @endif
    </form>

    @if ($topics->isNotEmpty())
        <nav class="help-chips" aria-label="Topics">
            <a class="help-chip {{ $topic === null ? 'on' : '' }}" href="{{ $url(['q' => $q]) }}" @if ($topic === null) aria-current="true" @endif>
                All topics <span class="help-count">{{ $total }}</span>
            </a>
            @foreach ($topics as $t)
                <a class="help-chip {{ $topic === $t['name'] ? 'on' : '' }} {{ $t['count'] === 0 ? 'zero' : '' }}"
                    href="{{ $url(['q' => $q, 'topic' => $t['name']]) }}" @if ($topic === $t['name']) aria-current="true" @endif>
                    {{ $t['name'] }} <span class="help-count">{{ $t['count'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <p class="help-status" id="help-status" role="status" aria-live="polite">
        @if ($count > 0)
            {{ $count }} {{ \Illuminate\Support\Str::plural('answer', $count) }}@if ($q !== '') found for &ldquo;{{ $q }}&rdquo;@endif
        @endif
    </p>

    <div id="help-list">
        @foreach ($groups as $name => $items)
            <section class="help-group" data-help-group>
                <h2>{{ $name }}</h2>
                @foreach ($items as $faq)
                    <details class="help-item" id="faq-{{ $faq->id }}" data-help-item{{ ($openAll || (int) session('help_feedback_id') === (int) $faq->id) ? ' open' : '' }}>
                        <summary>{{ \App\Support\FaqHighlight::question($faq->question, $q) }}</summary>
                        <div class="help-answer">{{ $faq->answer_html }}</div>
                        @include('help._feedback', ['faq' => $faq, 'isGuest' => $isGuest, 'vote' => $votes[$faq->id] ?? null])
                    </details>
                @endforeach
            </section>
        @endforeach
    </div>

    @if ($count > 0)
        {{-- Shown by help-filter.js only (hidden until then): typing found nothing among the questions on this page. --}}
        <div class="empty help-empty" id="help-js-empty" hidden>
            <i class="fa-regular fa-circle-question"></i>
            <b>No answers match &ldquo;<span id="help-js-q"></span>&rdquo;</b>
            <span>Press Enter to search every question, or clear the search.</span>
            <div class="help-actions">
                <button type="button" class="btn btn-sm" id="help-js-clear"><i class="fa-solid fa-xmark"></i> Clear the search</button>
                <a class="btn btn-sm btn-primary" href="#still-need-help"><i class="fa-solid fa-life-ring"></i> Still need help?</a>
            </div>
        </div>
    @endif

    @if ($count === 0)
        <div class="empty help-empty" id="help-empty">
            <i class="fa-regular fa-circle-question"></i>
            @if ($q !== '')
                <b>No answers found for &ldquo;{{ $q }}&rdquo;</b>
                <span>Check the spelling or try fewer or different words.</span>
            @elseif ($topic)
                <b>No answers in this topic yet</b>
                <span>Try another topic.</span>
            @else
                <b>No questions here yet</b>
                <span>Nothing has been published for you so far.</span>
            @endif
            <div class="help-actions">
                @if ($q !== '')
                    <a class="btn btn-sm" href="{{ $url(['topic' => $topic]) }}"><i class="fa-solid fa-xmark"></i> Clear the search</a>
                    @if ($topic && $total > 0)
                        <a class="btn btn-sm" href="{{ $url(['q' => $q]) }}">Search all topics ({{ $total }})</a>
                    @endif
                @endif
                <a class="btn btn-sm btn-primary" href="#still-need-help"><i class="fa-solid fa-life-ring"></i> Still need help?</a>
            </div>
        </div>
    @endif

    @if ($count >= 5)
        <p class="help-top"><a href="#help-top"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Back to top</a></p>
    @endif

    @include('help._still-need-help')
    <script src="{{ asset('js/help-filter.js') }}?v={{ @filemtime(public_path('js/help-filter.js')) }}" defer></script>
    <script src="{{ asset('js/help-feedback.js') }}?v={{ @filemtime(public_path('js/help-feedback.js')) }}" defer></script>
    <script src="{{ asset('js/help-a11y.js') }}?v={{ @filemtime(public_path('js/help-a11y.js')) }}" defer></script>
</div>