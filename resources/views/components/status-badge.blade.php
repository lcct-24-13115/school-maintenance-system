@props(['status'])

@php
    $colors = [
        'submitted' => 'bg-gray-100 text-gray-800',
        'pending_authorization' => 'bg-yellow-100 text-yellow-800',
        'approved' => 'bg-blue-100 text-blue-800',
        'assigned' => 'bg-indigo-100 text-indigo-800',
        'in_progress' => 'bg-amber-100 text-amber-800',
        'work_completed' => 'bg-teal-100 text-teal-800',
        'closed' => 'bg-green-100 text-green-800',
        'disapproved' => 'bg-red-100 text-red-800',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-bold '.($colors[$status->value] ?? 'bg-gray-100 text-gray-800')]) }}>{{ $status->label() }}</span>
