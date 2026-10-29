@props(['disabled' => false])

{{-- Styled globally by public/css/style.css (input[type=...]) --}}
<input @disabled($disabled) {{ $attributes }}>
