<?php

/*
|--------------------------------------------------------------------------
| Permission matrix (role slug => capabilities)
|--------------------------------------------------------------------------
| Read through User::can_do('capability'). Change a role's powers HERE, not in code.
|
| Capabilities:
|   view_all_tickets   see every non-confidential ticket
|   view_confidential  see confidential tickets too
|   flag_emergency     create/set an emergency ticket
|   clear_emergency    remove an emergency flag
|   authorize          (matrix entry only) WHO may authorize/disapprove is school.workflow.authorizers since Phase 4;
|   disapprove         the policy reads that config. Kept here so the matrix stays readable.
|   send_for_authorization  move a Submitted ticket to Pending Authorization (Phase 4)
|   assign             assign / reassign a ticket (Phase 4 action)
|   work               accept and complete tasks
|   close              close a ticket (Phase 4 action)
|   add_attachment     add files to ANY visible ticket (the requester of their own non-closed ticket and the
|                      assignee can add files through TicketPolicy@addAttachment without this capability)
|   view_analytics     open /reports: charts and numbers (Phase 6, built in Merge Phase M4)
|   export_reports     download the CSV from /reports/export (Phase 7, built in M4). Logged to audit_logs.
|                      Both only ever cover the tickets the user may see (confidential: view_confidential).
|   manage_users       open /admin/users: add, activate / deactivate, reset password, change role and department (Phase 6)
|   manage_settings    edit /admin/settings: SLA hours, reopen window (Merge Phase M1)
|   manage_categories  open /admin/categories: add, edit, hide, reorder and delete categories (Phase 5)
|   cancel_own         cancel YOUR OWN ticket, before it is Assigned (Merge Phase M2). Every role has it.
|   reopen_own         reopen YOUR OWN Work Completed / Closed ticket inside reopen_window_days (M2). Every role has it.
|   request_info       ask the requester for information: ticket goes to Waiting for User (M2)
|   manage_qr_codes    open /admin/qr-codes: make, edit, print and delete QR codes for Login / Register / New repair request / any link (Phase 17)
|   view_audit_logs    open /admin/audit-logs, the read-only audit trail (Merge Phase M3)
|   view_email_log     open /admin/email-log: every e-mail the system sent, its real result, and "Send again" for a failed ticket e-mail
|   manage_faq          add, edit, order, hide and delete the Help & FAQ entries (Help & FAQ Phase 1). Everyone can READ the entries meant for their role.
|   escalate           escalate to the President / authorizer (M2). Handing it back is the authorizer's
|                      (school.workflow.authorizers), like approve/disapprove.
|
| Requesters always see their own tickets, and staff always see tickets assigned to them;
| that is not a capability. See also school.department_visibility_roles.
*/

return [
    // Role model (Phase 4A): Requester < Department Staff < Department Head < System Administrator.
    //   requester         Student, Faculty and non-teaching Staff (told apart by users.requester_type). Files and follows own tickets.
    //   staff             Department Staff: does the work, takes / transfers its OWN tickets, completes tasks, asks the requester for info.
    //   head              Department Head: Staff + assigns anyone in the department, sends for authorization, authorizes, closes, escalates, reports, audit log, FAQ.
    //   maintenance_admin Admin of the Maintenance department: Head + users, settings, categories, QR codes, e-mail log (kept as is; see OPEN_QUESTIONS).
    //   system_admin      System Administrator: GLOBAL, all of the above across every department.
    // Scope is NOT here: staff, head and maintenance_admin are limited to users.department_id
    // (school.department_scoped_roles); system_admin is global (school.global_roles). Phase 4B/4C enforce it.
    // CONFIRM WITH CLIENT / TEACHER: the exact split of powers between Staff, Head and Admin.

    // System Administrator: global. Same capabilities as maintenance_admin, not limited to a department.
    'system_admin' => [
        'view_all_tickets', 'view_confidential', 'flag_emergency', 'clear_emergency',
        'send_for_authorization', 'assign', 'work', 'close', 'add_attachment', 'view_analytics', 'export_reports', 'manage_users', 'manage_settings', 'manage_categories', 'manage_qr_codes',
        'cancel_own', 'reopen_own', 'request_info', 'escalate', 'view_audit_logs',
        'view_email_log',
        'manage_faq',
    ],

    'maintenance_admin' => [
        'view_all_tickets', 'view_confidential', 'flag_emergency', 'clear_emergency',
        'send_for_authorization', 'assign', 'work', 'close', 'add_attachment', 'view_analytics', 'export_reports', 'manage_users', 'manage_settings', 'manage_categories', 'manage_qr_codes',
        'cancel_own', 'reopen_own', 'request_info', 'escalate', 'view_audit_logs',
        // CONFIRM WITH CLIENT (E-mail log): only the Maintenance Admin sees the e-mail log.
        'view_email_log',
        // CONFIRM WITH CLIENT (Help & FAQ): who writes the FAQ.
        'manage_faq',
    ],

    // Head of Maintenance: everything the Admin can do EXCEPT managing users / settings / categories / QR codes / the e-mail log.
    'head' => [
        'view_all_tickets', 'view_confidential', 'flag_emergency', 'clear_emergency',
        'send_for_authorization', 'assign', 'work', 'close', 'add_attachment', 'view_analytics', 'export_reports',
        'cancel_own', 'reopen_own', 'request_info', 'escalate', 'view_audit_logs',
        'manage_faq',
    ],

    // Maintenance Staff: sees all (non-confidential) tickets and does the work. 'assign' is limited by the policy to
    // taking an unassigned ticket / transferring their OWN ticket (User::isStaffOnly).
    'staff' => [
        'view_all_tickets', 'flag_emergency', 'send_for_authorization', 'assign', 'work', 'add_attachment',
        'request_info',
        'cancel_own', 'reopen_own',
    ],

    // CONFIRM WITH CLIENT: Finance sees all tickets EXCEPT confidential ones.
    'finance' => [
        'view_all_tickets', 'view_analytics', 'export_reports',
        'cancel_own', 'reopen_own',
    ],

    // CONFIRM WITH CLIENT: the proposal does not say whether the President may flag emergencies.
    'president' => [
        'view_all_tickets', 'view_confidential', 'authorize', 'disapprove', 'flag_emergency',
        'view_analytics', 'export_reports', 'view_audit_logs',
        'cancel_own', 'reopen_own',
    ],

    // CONFIRM WITH CLIENT: no global powers. Requesters see their own tickets;
    // department heads also see their own department (school.department_visibility_roles).
    'requester' => ['cancel_own', 'reopen_own'],
    'department_head' => ['cancel_own', 'reopen_own'],
    'treasurer' => ['cancel_own', 'reopen_own'],
    'custodian' => ['cancel_own', 'reopen_own'],
];
