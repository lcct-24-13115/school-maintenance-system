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
|   manage_users       manage user accounts (later phase)
|   manage_settings    edit /admin/settings: SLA hours, reopen window (Merge Phase M1)
|   cancel_own         cancel YOUR OWN ticket, before it is Assigned (Merge Phase M2). Every role has it.
|   reopen_own         reopen YOUR OWN Work Completed / Closed ticket inside reopen_window_days (M2). Every role has it.
|   request_info       ask the requester for information: ticket goes to Waiting for User (M2)
|   view_audit_logs    open /admin/audit-logs, the read-only audit trail (Merge Phase M3)
|   escalate           escalate to the President / authorizer (M2). Handing it back is the authorizer's
|                      (school.workflow.authorizers), like approve/disapprove.
|
| Requesters always see their own tickets, and staff always see tickets assigned to them;
| that is not a capability. See also school.department_visibility_roles.
*/

return [
    // CONFIRM WITH CLIENT: the proposal only names "Admin" and "Finance" as initial groups.
    // maintenance_admin is the proposal's "Admin".
    'maintenance_admin' => [
        'view_all_tickets', 'view_confidential', 'flag_emergency', 'clear_emergency',
        'send_for_authorization', 'assign', 'work', 'close', 'add_attachment', 'view_analytics', 'export_reports', 'manage_users', 'manage_settings',
        'cancel_own', 'reopen_own', 'request_info', 'escalate', 'view_audit_logs',
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
