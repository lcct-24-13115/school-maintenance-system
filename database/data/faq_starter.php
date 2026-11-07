<?php

/*
| Starter questions of the Help & FAQ page (run: php artisan faq:seed).
| Row = [topic, audience, public?, question, answer, sort_order]
|   audience  ['all'] or role slugs.   public?  true = may be read before signing in (only with ['all']).
| An answer may use: blank line = new paragraph, "- " bullets, "1. " steps, **bold**, [label](/path).
|
| SAMPLE TEXT: Phase 4 replaces this file with the full, checked content (35+ answers).
| faq:seed only ADDS rows that are missing (matched by the question). It never changes a row that is already there.
*/

return [
    ['Getting started', ['all'], true, 'What is the Maintenance Request System?',
        "It is the online service desk of the school's maintenance office. You use it to report something that needs repair, and to follow what happens to your request until the work is done.",
        10],

    ['Getting started', ['all'], true, 'How do I sign in?',
        "1. Open the sign-in page.\n2. Type your e-mail address and your password.\n3. Press Sign in.\n\nIf you type a wrong password several times, sign-in is locked for a few minutes. Wait, then try again.",
        20],

    ['Getting started', ['all'], true, 'I forgot my password. What do I do?',
        "On the sign-in page choose **Forgot password?** and follow the steps. If you do not get an e-mail, check your spam folder or ask the maintenance office.",
        30],

    ['Sending a repair request', ['all'], false, 'How do I send a repair request?',
        "1. Open the new repair request form from the menu.\n2. Say where the problem is and what it is.\n3. Add photos if you have them.\n4. Press Submit.\n\nYour request gets a ticket number so you can follow it.",
        10],

    ['Sending a repair request', ['all'], false, 'Can I attach photos or files?',
        "Yes. You can add up to 5 files at a time, each up to 5 MB. Photos and common documents are accepted. Other kinds of files are refused.",
        20],

    ['Sending a repair request', ['all'], false, 'How do I know what is happening with my request?',
        "Open your ticket. It shows the status and the history. You also get a notification when something changes.",
        30],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I accept a task?',
        "Open the ticket that is assigned to you and accept the task. The ticket moves to **In Progress**.",
        10],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I complete a preventive maintenance (PM) task?',
        "Open the PM ticket that is In Progress and fill in the Complete PM form: the date, the technician, the checklist and your notes. The ticket then moves to **Work Completed**.",
        20],
];
