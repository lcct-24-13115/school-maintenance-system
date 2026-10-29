@props(['value'])

<label {{ $attributes->merge(['class' => 'lbl']) }}>
    {{ $value ?? $slot }}
</label>
