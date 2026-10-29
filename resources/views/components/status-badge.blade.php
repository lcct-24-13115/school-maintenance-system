@props(['status'])

@php
    $classes = [
        'submitted' => 'st-open',
        'pending_authorization' => 'st-waitingforuser',
        'approved' => 'st-reopened',
        'assigned' => 'st-assigned',
        'in_progress' => 'st-inprogress',
        'work_completed' => 'st-resolved',
        'closed' => 'st-closed',
        'disapproved' => 'st-escalated',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'badge '.($classes[$status->value] ?? 'st-closed')]) }}>{{ $status->label() }}</span>
