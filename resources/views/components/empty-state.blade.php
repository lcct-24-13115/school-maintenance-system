@props(['icon' => 'fa-regular fa-folder-open', 'title' => null])

{{-- Phase 18: one empty-state look for every list. Usage: <x-empty-state icon="fa-regular fa-bell" title="No notifications yet.">Helper line</x-empty-state>
     Add class="compact" inside a small card. Extra content (a button) can go in the slot. --}}
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <i class="{{ $icon }}"></i>
    @if ($title)<b>{{ $title }}</b>@endif
    <span>{{ $slot }}</span>
</div>
