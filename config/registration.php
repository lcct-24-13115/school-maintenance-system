<?php

/*
| "I am a ..." on the registration form (/register).
|
| Each key below is one option. The form, the server-side validation and the admin's "Waiting for approval" panel all
| follow this table, so to change the rules you only edit this file (then: php artisan config:clear).
|
|   label         text on the option (also shown to the admin)
|   icon          Font Awesome 6 class
|   school_email  true  = must use the school email (config school.registration.email_domain)
|                 false = any email the person can read. Such a registration ALWAYS waits for approval,
|                         even when REQUIRE_ACCOUNT_APPROVAL=false.
|   department    true  = show the Department dropdown, false = hide it
|   affiliation   the first descriptive field (label, placeholder, help text, required?)
|   purpose       "Why do you need an account?" (placeholder, required?)
|
| To remove an option, delete its block. 'default' must be one of the keys.
*/
return [
    'default' => 'STUDENT',

    'types' => [
        'STUDENT' => [
            'label' => 'Student',
            'icon' => 'fa-user-graduate',
            'school_email' => true,
            'department' => true,
            'affiliation' => [
                'label' => 'Program & year level',
                'placeholder' => 'e.g. BSIT, 3rd year',
                'help' => 'Helps the office confirm who you are.',
                'required' => false,
            ],
            'purpose' => [
                'placeholder' => 'e.g. I want to report broken facilities and follow them up online.',
                'required' => false,
            ],
        ],

        'FACULTY/STAFF' => [
            'label' => 'Faculty / Staff',
            'icon' => 'fa-chalkboard-user',
            'school_email' => true,
            'department' => true,
            'affiliation' => [
                'label' => 'Department / office',
                'placeholder' => 'e.g. College of Education',
                'help' => 'Helps the office confirm who you are.',
                'required' => false,
            ],
            'purpose' => [
                'placeholder' => 'e.g. I need to report repairs for my classroom or office.',
                'required' => false,
            ],
        ],

        'PARENT/GUARDIAN' => [
            'label' => 'Parent / Guardian',
            'icon' => 'fa-people-roof',
            'school_email' => false,
            'department' => false,
            'affiliation' => [
                'label' => "Student's name & program",
                'placeholder' => 'e.g. Juan Dela Cruz, BSIT 2nd year',
                'help' => 'The office uses this to confirm who you are.',
                'required' => true,
            ],
            'purpose' => [
                'placeholder' => 'e.g. I want to report a problem on behalf of my child.',
                'required' => false,
            ],
        ],
    ],
];