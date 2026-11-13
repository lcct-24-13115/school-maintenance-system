# Phase 5 — Department management (admin)

## PREAMBLE

> `[Ilagay ang PREAMBLE]` — the specification opens with this placeholder, so the official preamble
> text has NOT been supplied yet. The text below is a provisional preamble written for this build;
> replace it with the client's wording when it arrives (OPEN_QUESTIONS #110).

This document describes the **administration of school departments** for the LCCT Maintenance
Ticketing System. It follows Phase 4 (roles scoped per department): a person's powers are derived
from `users.role_id` + `users.department_id`, so the department table is now part of the security
model, not just a label. For that reason the structure of departments is a **school-wide** setting
and only the **System Administrator** may change it (`manage_departments` in
`config/permissions.php`). The Maintenance Admin keeps its own department-scoped admin pages
(users, settings, categories, QR codes) unchanged.

No department name, code or id is hardcoded. Every rule compares rows (`departments.id`,
`users.department_id`, `requests.department_id`, `requests.assigned_department_id`).

## 1. Capability and access

| Item | Value |
|---|---|
| Capability | `manage_departments` |
| Who has it | `system_admin` only |
| Checked in | `Admin/DepartmentController::authorizeManage()` — `abort_unless(..., 403)`, the same pattern as `CategoryController` / `UserController` |
| Sidebar | Administration → **Departments**, shown only when `can_do('manage_departments')` |
| Routes | `admin/departments.*` (`routes/web.php`) |

Everyone else (including `maintenance_admin`, `head`, `staff`, `finance`, `president`, `requester`)
gets **403** on every department route. Guests are sent to sign-in by the `auth` middleware.

## 2. Screens

| Screen | What it does |
|---|---|
| `admin/departments/index` | List (name, code, head, people, open/total tickets, active flag), Add button, Edit link, Activate / Deactivate, Delete. |
| `admin/departments/create` | Add: name (unique), code (unique, upper-cased), description, "receives tickets" checkbox. New departments start **active**. |
| `admin/departments/edit` | Edit the same fields + **Department Head** (`head_user_id`), list of active Department Staff in the office, ticket counts, link to Admin › Users, and the Delete panel. |

`is_active` is **not** editable on the edit form: switching it off must go through `deactivate()` so
the open-ticket guard cannot be bypassed.

## 3. Assigning a Head and Staff to a department

Use the **existing** `Admin/UserController` (Admin › Users) — it already owns role + department
selection on both the Add form and each row's Edit panel. Phase 5 tightens it:

- New assignments may only pick an **active** department (`Rule::exists(...)->where('is_active', true)`).
- In the Edit panel an inactive department is shown as `(inactive)` and disabled, but a person's
  **current** department is still accepted so an unrelated role change can be saved on old data.
- The department edit page also sets the explicit `departments.head_user_id` pointer, offering only
  active Heads / Maintenance Admins of that department plus global administrators.

`head_user_id` was only a pointer since multi-department Phase 1; this is the phase that finally
assigns it (see `docs/MULTI_DEPARTMENT_NOTES.md`).

## 4. Deactivating / removing a department

"Open" tickets = `TicketStatus::pendingValues()` (Submitted … Reopened, Waiting for User, Escalated),
i.e. **not** Closed / Disapproved / Cancelled. Both sides of a ticket count: the office that FILED it
(`requests.department_id`) and the office that RECEIVES it (`requests.assigned_department_id`).
`Department::relatedTickets()` / `openTickets()` are the single definitions.

| Action | Rule |
|---|---|
| **Deactivate**, no open tickets | Allowed. `is_active = false`; the department disappears from new-ticket / registration forms. |
| **Deactivate**, with open tickets, **no** `transfer_to` | **Refused** with a clear message naming the count. |
| **Deactivate**, with open tickets + `transfer_to` | Only allowed when the target is a **different ACTIVE** department. All open tickets' `department_id` / `assigned_department_id` are moved to the target in one transaction. Finished tickets are **not** moved (they keep the old department for history). |
| **Delete** (hard) | **Refused whenever ANY ticket** points at the department, open or finished. Deactivate instead. Other references (users, categories, budgets, …) are refused by the database FK and reported as a friendly error. |
| **Activate** | Allowed any time; makes it available on new forms again. |

The transfer does **not** create status history lines — it is a re-route of the ticket's department
columns, done with a query-builder update inside `DB::transaction()`. It is recorded in the audit log.

After deactivation the department no longer appears in:

- `RepairRequestController@create` (`Department::active()`), with a server-side guard in
  `StoreRepairRequestRequest` (`Rule::exists(...)->where('is_active', true)`);
- `RegisteredUserController@create` and its `store` validation;
- new assignments in Admin › Users.

It **still** appears in the ticket list / history filters and on old tickets, so nothing disappears
from the record.

## 5. Audit log

`AuditLogger` gained five actions (all listed in `AuditLogger::actions()`):

`department_created`, `department_updated`, `department_activated`,
`department_deactivated`, `department_deleted`.

Every controller action writes one row with the department as subject. Update rows carry `before`
values and the list of changed fields; deactivate rows carry `open_tickets`, `transfer_to`,
`moved_received`, `moved_filed`. No secrets are logged (`AuditLogger::cleanMeta()` still applies).

## 6. Tests

`tests/Feature/DepartmentManagementTest.php`:

- access: System Admin OK; every other role (including Maintenance Admin) gets 403 on all routes;
  guests go to sign-in; sidebar link is visible only to the System Admin;
- CRUD: add, edit (name / code / description / service flag / head), duplicate name & code refused,
  delete when unused;
- deactivate: blocked by open tickets; allowed with a transfer target; open tickets moved, finished
  tickets kept; activation restores it;
- inactive department: absent from the repair form, rejected by a hand-made POST, absent from
  registration, rejected by Admin › Users;
- delete: refused when any ticket exists;
- audit: one entry per change with the right action and meta.

## Not in scope (per the request)

Reports and SLA were explicitly **not** built in this phase. No department management UI on mobile
beyond the shared responsive layout, and no dashboards.
