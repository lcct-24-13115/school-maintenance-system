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

    /*
    | File attachments (Phase 3). One set of rules used EVERYWHERE files are accepted
    | (App\Rules\ValidAttachment + App\Services\AttachmentService).
    |
    |  types   extension => the canonical mime we store, the mimes finfo may report for a genuine file,
    |          and whether the browser may show it inline (images/PDF) or must download it.
    |          The mime is detected on the SERVER from the file contents; the browser's mime is never trusted.
    |  max_kb / max_files   per file / per upload.
    |  blocked_inner_extensions   "file.php.jpg" style names are refused when a middle part is one of these
    |          (or any other allowed extension, e.g. "file.png.jpg").
    */
    'attachments' => [
        'max_kb' => 5120,       // 5 MB each
        'max_files' => 5,       // per upload

        'types' => [
            'jpg' => ['mime' => 'image/jpeg', 'detected' => ['image/jpeg'], 'inline' => true],
            'jpeg' => ['mime' => 'image/jpeg', 'detected' => ['image/jpeg'], 'inline' => true],
            'png' => ['mime' => 'image/png', 'detected' => ['image/png'], 'inline' => true],
            'webp' => ['mime' => 'image/webp', 'detected' => ['image/webp'], 'inline' => true],
            'pdf' => ['mime' => 'application/pdf', 'detected' => ['application/pdf'], 'inline' => true],
            // Office files are zip containers; finfo answers differently on different systems, so the
            // zip variants are accepted AND the zip's inner structure is checked (see AttachmentService).
            'docx' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'detected' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream',
                ],
                'inline' => false,
            ],
            'xlsx' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'detected' => [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream',
                ],
                'inline' => false,
            ],
        ],

        'blocked_inner_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
            'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi',
            'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'xml', 'swf', 'jar', 'vbs', 'ps1', 'htaccess',
        ],
    ],
];
