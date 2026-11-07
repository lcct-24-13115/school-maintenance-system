@props(['topic', 'label' => 'Help with this page', 'row' => false])

{{-- FAQ Phase 4E: a link to the Help & FAQ page, already filtered to one topic.
     Usage:  <x-help-link topic="Reports and records" row />
       topic  the exact topic name of the FAQ (see database/data/faq_starter/*.php)
       label  the link text (default "Help with this page")
       row    true = wrap it in a right-aligned row (class help-link-row), so it sits on its own line under the title
     It is shown only to a signed-in person who may read at least one published entry of that topic, so it can never
     lead to an empty page (topic hidden by the FAQ manager, or meant for other roles). The pages that use it are
     already limited to the roles that may open them. --}}
@php
    $helpUser = auth()->user();
    $helpShow = $helpUser !== null && \App\Models\Faq::visibleTo($helpUser)->contains('topic', $topic);
@endphp
@if ($helpShow)
    @if ($row)<div class="help-link-row">@endif
    <a href="{{ route('help.index', ['topic' => $topic]) }}" {{ $attributes->merge(['class' => 'help-link']) }}>
        <i class="fa-regular fa-circle-question"></i> {{ $label }}
    </a>
    @if ($row)</div>@endif
@endif