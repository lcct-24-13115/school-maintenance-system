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

    // Phase 15: how often (seconds) an open dashboard / ticket list / ticket page asks "anything new?" and reloads
    // itself when there is (never while someone is typing in a form). 0 turns the auto-refresh off.
    'live_refresh_seconds' => (int) env('LIVE_REFRESH_SECONDS', 8),

    // Public self-registration at /register (always creates a Requester). Set false to turn it off.
    // CONFIRM WITH CLIENT: should the school allow anyone to self-register in production?
    // Phase 2: the controller now really reads this switch. Default is CLOSED (registration was already closed by
    // the no-register patch). Turn on with ALLOW_PUBLIC_REGISTRATION=true in .env.
    'allow_public_registration' => env('ALLOW_PUBLIC_REGISTRATION', false),

    /*
    | Phase 2: which emails may register (App\Rules\SchoolEmail).
    |
    |  email_domain   only addresses ending in @this-domain. Empty = any domain.
    |  email_format   'domain'         any name@domain (teachers, staff and students)
    |                 'student_number' only studentnumber@domain, digits with optional hyphens, e.g. 24-12803@lcctanauan.edu.ph
    |                                  (the LCCT Ticketing rule)
    | CONFIRM WITH CLIENT: do requesters here include staff/teachers? If yes keep 'domain'.
    */
    /*
    | Phase 3: sign-in lock. After max_attempts wrong passwords (same email + same device) sign-in is locked for lock_minutes.
    | CONFIRM WITH CLIENT: 5 attempts / 15 minutes is the LCCT Ticketing rule.
    */
    'login' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),
    ],

    'registration' => [
        // Phase 3: true = an account whose email is not verified yet can only see the "verify your email" page.
        // Turn it on after Phase 1 email really works (php artisan mail:test). Seeded and admin-made accounts: set email_verified_at.
        'require_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),
        'email_domain' => env('SCHOOL_EMAIL_DOMAIN', 'lcctanauan.edu.ph'),
        'email_format' => env('SCHOOL_EMAIL_FORMAT', 'domain'),
    ],

    // Roles whose members also see the (non-confidential) tickets of their own department.
    // CONFIRM WITH CLIENT
    'department_visibility_roles' => ['department_head'],

    // Roles that may use the repair request form (/repairs/create).
    // Default keeps Phase 1 behaviour (every role). CONFIRM WITH CLIENT
    'filing_roles' => [
        'requester', 'department_head', 'treasurer', 'custodian', 'maintenance_admin', 'finance', 'president',
    ],

    // Phase 16 (Reports): CONFIRM WITH CLIENT
    //   stale_days      a pending ticket with no activity for this many days is listed as "stale"
    //   attention_limit how many rows the "Needs attention" list shows (the total is always shown)
    //   daily_days      how many days the "Tickets per day" bars cover
    'reports' => [
        'stale_days' => 3,
        'attention_limit' => 25,
        'daily_days' => 30,
    ],

    /*
    | Default values for the editable settings page (/admin/settings, Merge Phase M1).
    | The database table `settings` overrides these; this array is only the fallback.
    |
    |  sla_hours_routine    target hours from submission to completion for a routine ticket
    |  sla_hours_emergency  same, for a ticket flagged as emergency
    |  reopen_window_days   how long after completing/closing a ticket may be reopened (Phase 8)
    |  autoclose_waiting_days  days a ticket may wait for the requester before tickets:autoclose closes it (Phase 9; 0 = off)
    |
    | CONFIRM WITH CLIENT: the numbers are placeholders (routine = 120 h = the 5 working days printed on FO-08;
    | emergency = 24 h). Hours are CALENDAR hours, not working hours.
    */
    'settings_defaults' => [
        'sla_hours_routine' => 120,
        'sla_hours_emergency' => 24,
        'reopen_window_days' => 14,
        'autoclose_waiting_days' => 7,   // Phase 9: days without an answer before a Waiting for User ticket is auto-closed (0 = off)
    ],

    /*
    | Ticket workflow rules (Phase 4). Role slugs, see App\Models\Role.
    |
    |  authorizers               roles that may approve / disapprove a ticket (the recorded "Authorized by").
    |  closers                   roles that may close a ticket (enforced from Phase 4B).
    |  closure_requires          what must exist before a ticket can be closed (enforced from Phase 4B):
    |                            'authorization' and/or 'signed_evidence'.
    |  authorize_from_submitted  true: an authorizer may approve/disapprove straight from Submitted.
    |                            false: Maintenance must first send it for authorization (Pending Authorization).
    */
    'workflow' => [
        // CONFIRM WITH CLIENT: who the final signatory/authorizer is.
        'authorizers' => ['maintenance_admin'],
        // CONFIRM WITH CLIENT: who may formally close a ticket.
        'closers' => ['maintenance_admin'],
        // CONFIRM WITH CLIENT: what must be on file before closing.
        'closure_requires' => ['authorization', 'signed_evidence'],
        // CONFIRM WITH CLIENT: may the authorizer approve directly from Submitted?
        'authorize_from_submitted' => true,
    ],

    /*
    | Email notifications for tickets (Phase 13). One switch for all of them: TICKET_EMAILS=false in .env stops every
    | ticket email (the in-app alerts are not affected). The emails are QUEUED: a queue worker must be running
    | (php artisan queue:work) unless QUEUE_CONNECTION=sync. See APPLY-PHASE-13.txt.
    */
    'ticket_emails' => (bool) env('TICKET_EMAILS', true),

    // PM integration (CONFIRM WITH CLIENT)
    'pm' => [
        'auto_approve' => true,
        'require_note_for_unchecked' => true,
        'reminder_days_before' => (int) env('PM_REMINDER_DAYS', 3),        // 0 = no reminder
        'escalate_after_days' => (int) env('PM_ESCALATE_AFTER_DAYS', 2),   // days overdue before the supervisor is told; 0 = off
        'supervisor_roles' => ['president'],
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
