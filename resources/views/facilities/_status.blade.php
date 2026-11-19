@php
    $map = [
        'submitted' => ['Filed', '#2F5D95', '#e6f0fa', '#bcd5ee'],
        'endorsed' => ['Endorsed', '#8a6100', '#fff6d6', '#f1dd8f'],
        'noted' => ['Noted', '#17708f', '#e3f4fa', '#b9e0ee'],
        'approved' => ['Approved', '#1f8a4c', '#e6f6ec', '#bfe6cc'],
        'returned' => ['Returned', '#5b6b84', '#edf1f6', '#d9e1ec'],
        'closed' => ['Closed', '#5b6b84', '#edf1f6', '#d9e1ec'],
        'rejected' => ['Disapproved', '#c8372d', '#fdeceb', '#f3c9c5'],
    ];
    [$label, $fg, $bg, $bd] = $map[$status] ?? [ucfirst((string) $status), '#5b6b84', '#edf1f6', '#d9e1ec'];
@endphp
<span class="badge" style="color:{{ $fg }};background:{{ $bg }};border-color:{{ $bd }}">{{ $label }}</span>