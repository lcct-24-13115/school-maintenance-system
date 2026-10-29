{{-- Phase 4 (design): generic dashboard. /dashboard normally redirects to the role dashboard; this is the fallback page. --}}
<x-app-layout>
    <x-slot name="header">{{ __('Dashboard') }}</x-slot>

    <section class="hero" style="--hero:url('{{ asset('images/campus.jpg') }}')">
        <div class="hero-in">
            <div class="hero-txt">
                <span class="hero-eye">LCCT Maintenance</span>
                <h2>{{ __("You're logged in!") }}</h2>
                <p>Welcome, <b>{{ auth()->user()->name }}</b>. Go to your dashboard to see what needs your attention.</p>
                <div class="hero-act">
                    <a href="{{ route(auth()->user()->dashboardRoute()) }}" class="btn btn-primary"><i class="fa-solid fa-gauge-high"></i> Open my dashboard</a>
                    <a href="{{ route('tickets.index') }}" class="btn"><i class="fa-solid fa-list-check"></i> View tickets</a>
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
