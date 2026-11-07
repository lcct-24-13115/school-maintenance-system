{{-- PM E4: one event chip of the calendar. Needs $e (a MaintenanceCalendar event) and $canManage. --}}
@php
    $p = $e['extendedProps'];
    // A ticket opens the ticket. A projected date (no ticket yet) opens the schedule, but only for people who may change it.
    $href = $p['ticket_url'] ?? ($canManage ? $p['schedule_url'] : null);
    $tip = $p['status_label'].': '.$e['title']
        .($p['facility'] ? ' - '.$p['facility'] : '')
        .($p['ticket_no'] ? ' ('.$p['ticket_no'].')' : '');
    $style = 'background:'.$e['backgroundColor'].';color:'.$e['textColor'];
@endphp
@if ($href)
    <a href="{{ $href }}" class="pmc-ev" style="{{ $style }}" title="{{ $tip }}">{{ $e['title'] }}</a>
@else
    <span class="pmc-ev" style="{{ $style }}" title="{{ $tip }}">{{ $e['title'] }}</span>
@endif
