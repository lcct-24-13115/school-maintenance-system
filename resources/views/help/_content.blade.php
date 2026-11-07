{{-- PLACEHOLDER for Phase 2A. Phase 2B replaces this with the real design. --}}
@php($action = $isGuest ? route('help.public') : route('help.index'))

<h1>Help &amp; FAQ</h1>

<form method="GET" action="{{ $action }}">
    <input type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="Search the questions" aria-label="Search the questions">
    @if ($topic)
        <input type="hidden" name="topic" value="{{ $topic }}">
    @endif
    <button type="submit">Search</button>
</form>

<p>
    <a href="{{ $action }}{{ $q !== '' ? '?'.http_build_query(['q' => $q]) : '' }}">All topics ({{ $total }})</a>
    @foreach ($topics as $t)
        | <a href="{{ $action }}?{{ http_build_query(array_filter(['q' => $q, 'topic' => $t['name']])) }}">{{ $t['name'] }} ({{ $t['count'] }})</a>
    @endforeach
</p>

@forelse ($groups as $name => $items)
    <h2>{{ $name }}</h2>
    @foreach ($items as $faq)
        <details>
            <summary>{{ $faq->question }}</summary>
            <div>{{ $faq->answer_html }}</div>
        </details>
    @endforeach
@empty
    <p>No answers found{{ $q !== '' ? ' for '.$q : '' }}.</p>
    @if ($q !== '')
        <p><a href="{{ $action }}">Clear the search</a></p>
    @endif
@endforelse