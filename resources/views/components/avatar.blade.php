{{--
    Profile photo, or the first letter of the name when there is none.
    <x-avatar :user="$u" />  |  <x-avatar :user="$u" :size="28" />
    Uses the existing .avatar look (round, blue ring). The photo comes from the authorized route avatars.show.
--}}
@props(['user' => null, 'size' => null])

@php
    $style = $size ? "width:{$size}px;height:{$size}px;font-size:".round($size * 0.42).'px;' : '';
    $url = $user?->avatarUrl();
@endphp

@if ($url)
    <img class="avatar" src="{{ $url }}" alt="{{ $user->name }}" loading="lazy" style="{{ $style }}object-fit:cover">
@else
    <span class="avatar" style="{{ $style }}" aria-hidden="true">{{ $user?->initial() ?? '?' }}</span>
@endif
