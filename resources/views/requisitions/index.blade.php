<x-app-layout>
    <x-slot name="header">Requisition slips</x-slot>

    <div style="max-width:none">
        @if (session('status'))
            <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('error') }}</div></div>
        @endif

        @if (in_array(auth()->user()->role?->slug, config('school.filing_roles', []), true))
            <div style="margin:0 0 18px">
                <a href="{{ route('requisitions.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New requisition slip</a>
            </div>
        @endif

        @if ($waiting->isNotEmpty())
            <div class="card" style="border-color:var(--blue)">
                <div class="card-h"><h3>Waiting for your signature ({{ $waiting->count() }})</h3></div>
                @foreach ($waiting as $f)
                    @include('requisitions._row', ['f' => $f])
                @endforeach
            </div>
        @endif

        <div class="card">
            <div class="card-h"><h3>All requisition slips you can see</h3></div>
            @forelse ($forms as $f)
                @include('requisitions._row', ['f' => $f])
            @empty
                <p class="hint">No requisition slips yet.</p>
            @endforelse
        </div>

        {{ $forms->links() }}
    </div>
</x-app-layout>
