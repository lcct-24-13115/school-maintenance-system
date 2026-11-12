{{--
    Phase 4 (design): role dashboard. Data is unchanged: $title (string) and $cards (label => ['count' => n, 'url' => ...]).
    A plain number instead of an array is still accepted, so an older controller will not break the page.
--}}
@php
    $user = auth()->user();

    // icon + colour for a card, picked from its label (decoration only)
    $look = function (string $label): array {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'emergency'), str_contains($l, 'overdue') => ['fa-triangle-exclamation', 'var(--red)'],
            str_contains($l, 'pending'), str_contains($l, 'waiting'), str_contains($l, 'new'), str_contains($l, 'review') => ['fa-hourglass-half', 'var(--gold)'],
            str_contains($l, 'progress') => ['fa-screwdriver-wrench', 'var(--deep)'],
            str_contains($l, 'closed'), str_contains($l, 'complete'), str_contains($l, 'approved') => ['fa-circle-check', 'var(--green)'],
            str_contains($l, 'inventory') => ['fa-boxes-stacked', 'var(--violet)'],
            default => ['fa-ticket', 'var(--blue)'],
        };
    };

    $canFile = in_array($user->role?->slug, config('school.filing_roles', []), true);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $title }}</x-slot>

    <section class="hero" style="--hero:url('{{ asset('images/campus.jpg') }}')">
        <div class="hero-in">
            <div class="hero-txt">
                <span class="hero-eye">{{ $title }}</span>
                <h2>Hi {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}, welcome back</h2>
                <p>Signed in as <b>{{ $user->role->name }}</b>. Open a number below to see the tickets behind it.</p>
                <div class="hero-act">
                    @if ($canFile)
                        <a href="{{ route('repairs.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> File a repair request</a>
                    @endif
                    <a href="{{ route('tickets.index') }}" class="btn"><i class="fa-solid fa-list-check"></i> View tickets</a>
                </div>
            </div>

            {{-- Phase 15: when a ticket you can see last changed (the page reloads itself when something new happens) --}}
            <div class="hero-last">
                <span class="hl-k"><i class="fa-regular fa-clock"></i> Last update</span>
                <b>{{ $lastUpdate ? $lastUpdate->format('M j, Y g:i A') : '—' }}</b>
                <small>{{ $lastUpdate ? $lastUpdate->diffForHumans() : 'No tickets yet' }}</small>
            </div>
        </div>
    </section>

    @if (count($cards))
        <div class="stats">
            @foreach ($cards as $label => $card)
                @php
                    $count = is_array($card) ? ($card['count'] ?? 0) : $card;
                    $url = is_array($card) ? ($card['url'] ?? null) : null;
                    $shown = is_array($card) && isset($card['display']) ? $card['display'] : $count;   // PM E3: a card may show text such as "87.5%"
                    [$icon, $colour] = $look((string) $label);
                @endphp

                @if ($url)
                    <a href="{{ $url }}" class="stat" style="--c:{{ $colour }};text-decoration:none">
                @else
                    <div class="stat" style="--c:{{ $colour }}">
                @endif
                        <span class="ic"><i class="fa-solid {{ $icon }}"></i></span>
                        <div class="k">{{ $label }}</div>
                        <div class="v nums">{{ $shown }}</div>
                        @if ($url)
                            <div class="s">View list <i class="fa-solid fa-arrow-right"></i></div>
                        @endif
                @if ($url)
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @push('scripts')
        @include('partials.live-refresh')
    @endpush
</x-app-layout>
