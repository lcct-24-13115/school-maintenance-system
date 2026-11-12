# Role × capability matrix (Phase 4C)

Source of truth: `config/permissions.php` (capabilities), `config/school.php`
(`department_scoped_roles`, `global_roles`, `workflow.authorizers` / `closers`),
`app/Models/MaintenanceRequest.php` (`scopeVisibleTo()` / `isVisibleTo()`),
`app/Policies/TicketPolicy.php`, `app/Services/TicketWorkflow.php`.
Change a role's powers in `config/permissions.php`, not in code. No department is hardcoded:
scoping always compares `users.department_id` with the ticket's `assigned_department_id`.

## 1. Roles

| Slug | Label | Scope | Who they are |
|---|---|---|---|
| `requester` | Student | Own tickets only | Student, Faculty and non-teaching Staff. Told apart by `users.requester_type` (`STUDENT` / `FACULTY/STAFF` in `config/registration.php`). Same powers, same slug — not separate roles (OPEN_QUESTIONS #100). |
| `staff` | Department Staff | Own department (`users.department_id`) | Does the work: claims an unassigned ticket, transfers their own, accepts / completes tasks, asks the requester for info. |
| `head` | Department Head | Own department | Staff + assigns anyone in the department, sends for authorization, authorizes, closes, escalates, reports, audit log, FAQ. |
| `maintenance_admin` | Maintenance Admin | Own department (the Maintenance department) | Head + users, settings, categories, QR codes, e-mail log. Kept as the admin of Maintenance (OPEN_QUESTIONS #98). |
| `system_admin` | System Administrator | **Global** (all departments) | Same capabilities as `maintenance_admin`, not tied to a department. Not a ticket worker: cannot be assigned a ticket and gets no maintenance e-mails. |
| `finance` | Finance | (legacy, global read-only) | Read-only view of every non-confidential ticket, reports and CSV export. **Not** department-scoped: a Finance *account* (role `finance`) still sees Registrar tickets; a Department Staff *in* Finance (`staff` + `department_id` = Finance) does not. See §4. |
| `custodian` | Property Custodian | Own tickets | No global powers. Files requests and sees only their own. |
| `president`, `department_head`, `treasurer` | (retired) | — | Seeded only under PHPUnit; still handled by the matrix/policies so they can be brought back. |

Which roles are scoped and which are global is configuration:
`school.department_scoped_roles = ['staff', 'head', 'maintenance_admin']`,
`school.global_roles = ['system_admin']`
(`Role::departmentScopedSlugs()`, `Role::globalSlugs()`, `User::scopedDepartmentId()`).
A scoped account with no department has no scope: it sees nothing and cannot claim / assign / transfer.

## 2. Capability matrix

`✓` = has the capability. `—` = does not. Visibility (§3) still applies on top of every row.

| Capability | requester | staff | head | maintenance_admin | system_admin | finance | custodian |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `view_all_tickets` (non-confidential) | — | ✓* | ✓* | ✓* | ✓ | ✓ | — |
| `view_confidential` | — | — | ✓* | ✓* | ✓ | — | — |
| `flag_emergency` | — | ✓* | ✓* | ✓* | ✓ | — | — |
| `clear_emergency` | — | — | ✓* | ✓* | ✓ | — | — |
| `send_for_authorization` | — | ✓* | ✓* | ✓* | ✓ | — | — |
| `assign` | — | ✓† | ✓* | ✓* | ✓ | — | — |
| `work` (accept / complete) | — | ✓* | ✓* | ✓* | ✓ | — | — |
| `close` | — | — | ✓* | ✓* | ✓ | — | — |
| `add_attachment` (any visible ticket) | — | ✓* | ✓* | ✓* | ✓ | — | — |
| `request_info` | — | ✓* | ✓* | ✓* | ✓ | — | — |
| `escalate` | — | — | ✓* | ✓* | ✓ | — | — |
| `view_analytics` (`/reports`) | — | — | ✓* | ✓* | ✓ | ✓ | — |
| `export_reports` (CSV / PDF) | — | — | ✓* | ✓* | ✓ | ✓ | — |
| `view_audit_logs` | — | — | ✓* | ✓* | ✓ | — | — |
| `view_email_log` | — | — | — | ✓* | ✓ | — | — |
| `manage_users` | — | — | — | ✓* | ✓ | — | — |
| `manage_settings` | — | — | — | ✓* | ✓ | — | — |
| `manage_categories` | — | — | — | ✓* | ✓ | — | — |
| `manage_qr_codes` | — | — | — | ✓* | ✓ | — | — |
| `manage_faq` | — | — | ✓* | ✓* | ✓ | — | — |
| `cross_department_transfer` (Phase 4C) | — | — | ✓* | ✓* | ✓ | — | — |
| `cancel_own`, `reopen_own` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

\* Scoped to the user's own department: the capability only works on tickets whose
`assigned_department_id` is the user's `users.department_id` (plus their own / assigned tickets).
`system_admin` and `finance` are not scoped. See §3.

† Staff `assign` is limited by `TicketPolicy@assign`: only their own ticket (transfer).
Claiming an unassigned ticket is `claim`, not `assign`.

Approve / disapprove is **not** in this matrix. It is decided by
`school.workflow.authorizers = ['head', 'maintenance_admin', 'system_admin']`
(`User::canAuthorize()`, `TicketPolicy@authorize`). Formal closure is
`school.workflow.closers` (same three roles).

## 3. Who sees which ticket

One rule, written twice so lists and single pages always agree:
`MaintenanceRequest::scopeVisibleTo($user)` (queries) and `isVisibleTo($user)` (one ticket).

| Person | Sees |
|---|---|
| Requester (Student / Faculty / non-teaching Staff) | Their own tickets (any department, any status). |
| Assignee | Tickets assigned to them (any department — this is how a cross-department assignment stays visible to the new assignee). |
| Department Staff / Head / Maintenance Admin | **Only** tickets whose `assigned_department_id` is their own `users.department_id`. `view_all_tickets` does not widen this. Confidential ones still need `view_confidential` (Head / Admin have it, Staff does not). No department = nothing. |
| System Administrator | Every ticket, including confidential. |
| Finance (role) | Every non-confidential ticket (global, not scoped). |
| Department Head (retired `department_head` role) | Non-confidential tickets of their own department (`school.department_visibility_roles`). |

**A ticket someone may not see answers `404`, not `403`**, so its existence is not revealed.
This covers `{ticket}` and `{attachment}` in URLs (route binding in `AppServiceProvider`).
The same 404 applies to every action route (`claim`, `assign`, `transfer`, `notes`, `replies`, …):
the binder runs before the policy.

## 4. Assignment rules (Phase 4C)

| Action | Who | Department rule | Reason required? |
|---|---|---|---|
| **Claim** an unassigned ticket (`POST /tickets/{ticket}/claim`) | Department Staff only (`staff`) | Ticket's `assigned_department_id` must be the staff member's own department. Other departments: 404 (hidden) via HTTP, refused in the workflow for direct calls. | No |
| **Transfer** your own ticket (`POST /tickets/{ticket}/transfer`) | Department Staff only, own ticket only | Target must be a colleague in the transferring staff member's own department. Cross-department target: refused. | Yes (`transfer_reason`, min 5 chars) |
| **Assign / reassign** (`POST /tickets/{ticket}/assign`) | Head / Maintenance Admin / System Administrator (`assign`) | Normally to staff **in the ticket's department**. A scoped actor can only act on their own department's tickets (assignee / requester excepted). | Reassignment: yes (`assign_note`). First assignment: no, unless cross-department (next row). |
| **Cross-department transfer** (assign to staff outside the ticket's department) | Head / Maintenance Admin / System Administrator only (`cross_department_transfer`) | Target's `users.department_id` ≠ ticket's `assigned_department_id` (including no department). Staff can never do this. | **Yes**, even for a first assignment (`assign_note`). Recorded in the history line (`meta.cross_department`). |
| **Accept task / mark completed** (board) | `work` roles | Same visibility as above (own department, or assigned to them). | No |

Enforcement is in three layers (all must agree):

1. **Visibility** — `scopeVisibleTo()` / `isVisibleTo()` + the `{ticket}` binder (hidden = 404).
2. **Policy** — `TicketPolicy@claim` / `@assign` / `@transfer` / `@addNote` / `@reply` (403 when visible but not allowed).
3. **Workflow** — `TicketWorkflow::claim()` / `transfer()` / `assign()` (flash error; a direct call cannot skip the rule). `TicketWorkflow::isCrossDepartmentAssignment()` is the single definition of "cross-department".

The Assign / Transfer dropdown (`TicketActions::assignableStaffFor()`):

- Department Staff: only colleagues in their own department.
- Head / Maintenance Admin / System Administrator: everyone (so a cross-department transfer can be picked; the reason is still required).
- `TicketActions::assignableStaff()` (all staff) is kept for callers that need the full list.

## 5. Notes and conversation

| Action | Who | Department rule |
|---|---|---|
| Add a history note (`POST /tickets/{ticket}/notes`) | Requester (own), assignee, authorizers, working roles (`assign` / `work` / `close` / flag / send) | Same visibility: Finance staff cannot note a Registrar ticket (404). Finance (role) is view-only: no notes. |
| Write in the conversation (`POST /tickets/{ticket}/replies`) | Same people as notes. Everyone who may *see* the ticket may *read* it. | Same as notes. |
| Internal notes | Staff side only (assignee, authorizers, working roles). Never the requester of the ticket. | Same as notes. |

## 6. Work screens (CheckRole route groups)

| Route group | Roles (`Role::workQueueSlugs()`) |
|---|---|
| `/maintenance/dashboard`, `/maintenance/board` (+ cards, accept, complete) | `staff`, `head`, `maintenance_admin`, `system_admin` |
| `/requester/dashboard` | `requester`, `department_head`, `treasurer` (retired) |
| `/finance/dashboard` | `finance` |
| `/custodian/dashboard` | `custodian` |
| `/president/dashboard` | `president` (retired) |

Opening the group is only the first gate: every number and every ticket inside still goes through
`visibleTo()`, so a Department Staff in Finance never counts or sees a Registrar ticket there either.

## 7. Tests

- `tests/Feature/TicketViewVisibilityTest.php` — view matrix (role × department), 404 vs 403,
  Finance staff vs Registrar ticket, confidential inside the department.
- `tests/Feature/DepartmentAssignmentRulesTest.php` — claim / assign / transfer / notes matrix
  (role × department), cross-department reason, dropdown scoping.
- `tests/Feature/DepartmentScopedRolesTest.php` — scoped vs global roles from config.
- `tests/Feature/StaffTicketRulesTest.php`, `tests/Feature/StaffHeadRolesTest.php` — staff / head split.
