<?php

return [
    // Form rules printed on the official forms
    'advance_notice' => [
        'facility' => ['days' => 3, 'type' => 'calendar'], // "3 days in advance"
        'requisition' => ['days' => 3, 'type' => 'working'],  // "three (3) working days"
        'repair' => ['days' => 5, 'type' => 'working'],  // "5 working days"
    ],

    // Emergency repairs are urgent by definition, so they skip the 5-day rule.
    'emergency_bypasses_advance_notice' => true,

    // How often (seconds) every open page checks for new alerts. Works without WebSockets.
    'poll_seconds' => 5,

    // Public self-registration at /register (always creates a Requester). Set false to turn it off.
    // CONFIRM WITH CLIENT: should the school allow anyone to self-register in production?
    'allow_public_registration' => env('ALLOW_PUBLIC_REGISTRATION', true),

    // Roles whose members also see the (non-confidential) tickets of their own department.
    // CONFIRM WITH CLIENT
    'department_visibility_roles' => ['department_head'],

    // Roles that may use the repair request form (/repairs/create).
    // Default keeps Phase 1 behaviour (every role). CONFIRM WITH CLIENT
    'filing_roles' => [
        'requester', 'department_head', 'treasurer', 'custodian', 'maintenance_admin', 'finance', 'president',
    ],
];
