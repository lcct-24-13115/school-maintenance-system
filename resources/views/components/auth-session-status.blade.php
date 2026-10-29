@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alert ok']) }}>
        <i class="fa-solid fa-circle-check"></i><span>{{ $status }}</span>
    </div>
@endif
