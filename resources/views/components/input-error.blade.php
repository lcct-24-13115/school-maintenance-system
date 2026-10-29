@props(['messages'])

@if ($messages)
    <div {{ $attributes->merge(['class' => 'field-err']) }}>
        @foreach ((array) $messages as $message)
            <div>{{ $message }}</div>
        @endforeach
    </div>
@endif
