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
|   view_analytics     see analytics (Phase 6)
|   export_reports     export reports (Phase 7)
|   manage_users       manage user accounts (later phase)
|
| Requesters always see their own tickets, and staff always see tickets assigned to them;
| that is not a capability. See also school.department_visibility_roles.
*/

return [
    // CONFIRM WITH CLIENT: the proposal only names "Admin" and "Finance" as initial groups.
    // maintenance_admin is the proposal's "Admin".
    'maintenance_admin' => [
        'view_all_tickets', 'view_confidential', 'flag_emergency', 'clear_emergency',
        'send_for_authorization', 'assign', 'work', 'close', 'add_attachment', 'view_analytics', 'export_reports', 'manage_users',
    ],

    // CONFIRM WITH CLIENT: Finance sees all tickets EXCEPT confidential ones.
    'finance' => [
        'view_all_tickets', 'view_analytics', 'export_reports',
    ],

    // CONFIRM WITH CLIENT: the proposal does not say whether the President may flag emergencies.
    'president' => [
        'view_all_tickets', 'view_confidential', 'authorize', 'disapprove', 'flag_emergency',
        'view_analytics', 'export_reports',
    ],

    // CONFIRM WITH CLIENT: no global powers. Requesters see their own tickets;
    // department heads also see their own department (school.department_visibility_roles).
    'requester' => [],
    'department_head' => [],
    'treasurer' => [],
    'custodian' => [],
];
