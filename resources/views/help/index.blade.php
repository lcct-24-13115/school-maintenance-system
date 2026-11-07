@if ($isGuest)
    <x-guest-layout wide>
        @include('help._content')
    </x-guest-layout>
@else
    <x-app-layout>
        <x-slot name="header">
            <h2 class="page-title">Help &amp; FAQ</h2>
        </x-slot>

        <div class="content">
            @include('help._content')
        </div>
    </x-app-layout>
@endif