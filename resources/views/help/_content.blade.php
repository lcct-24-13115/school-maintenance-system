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

<div class="help-page">
    @if ($isGuest)
        <p class="help-back"><a href="{{ route('login') }}"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a></p>
        <h1 class="form-title">Help <span>&amp; FAQ</span></h1>
        <p class="help-intro">Answers for everyone. <a href="{{ route('login') }}">Sign in</a> to see the answers made for your role.</p>
    @else
        <p class="help-intro">Quick answers about sending and following repair requests. Pick a topic or type a few words.</p>
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
                    <details class="help-item" data-help-item{{ $openAll ? ' open' : '' }}>
                        <summary>{{ \App\Support\FaqHighlight::question($faq->question, $q) }}</summary>
                        <div class="help-answer">{{ $faq->answer_html }}</div>
                    </details>
                @endforeach
            </section>
        @endforeach
    </div>

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

    @include('help._still-need-help')
</div>