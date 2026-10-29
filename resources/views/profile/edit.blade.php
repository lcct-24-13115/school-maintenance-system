<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">Profile</h2>
    </x-slot>

    <div class="content p6-wrap">
        <div class="card">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="card">
            @include('profile.partials.update-password-form')
        </div>

        <div class="card danger">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
