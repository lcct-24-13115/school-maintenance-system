<?php

return [
    // Form rules printed on the official forms
    'advance_notice' => [
        'facility'    => ['days' => 3, 'type' => 'calendar'], // "3 days in advance"
        'requisition' => ['days' => 3, 'type' => 'working'],  // "three (3) working days"
        'repair'      => ['days' => 5, 'type' => 'working'],  // "5 working days"
    ],

    // Emergency repairs are urgent by definition, so they skip the 5-day rule.
    'emergency_bypasses_advance_notice' => true,

    // How often (seconds) every open page checks for new alerts. Works without WebSockets.
    'poll_seconds' => 5,
];
