# Roles, permissions and ticket workflow

One page that answers: **who can do what, and how does a ticket move?**
The code is the source of truth; every rule below names the file that enforces it.
Decisions the school still has to confirm are listed in [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) (each is marked `// CONFIRM WITH CLIENT` in the code).

> **Heads-up before you rely on this page.** `config/school.php` currently says
> `workflow.authorizers = ['maintenance_admin']` and `workflow.closers = ['maintenance_admin']`,
> but `OPEN_QUESTIONS.md` (#14, #16) and `tests/Feature/TicketActionsTest.php` still describe the **President** as the authorizer.
> This page follows the **config as it is now**. See [LIMITATIONS.md](LIMITATIONS.md) item 2.

## 1. Roles

Roles live in the `roles` table (`App\Models\Role`). The slug is what the code uses.

### Roles created by `php artisan db:seed` (production)

| Slug | Label shown | Scope | What they are for |
|---|---|---|---|
| `requester` | Student | Own tickets | Student, Faculty and non-teaching Staff (told apart by `users.requester_type`). Files and follows their own tickets. Students are loaded with `php artisan students:import`. |
| `staff` | Department Staff | Own department (`users.department_id`) | Does the work: takes an unassigned ticket, transfers their own, completes tasks, asks the requester for information. |
| `head` | Department Head | Own department | Staff + assigns anyone in the department, sends for authorization, authorizes, closes, reports, audit log, FAQ. |
| `maintenance_admin` | Maintenance Admin | Own department (the Maintenance department) | Department Head powers + users, settings, categories, QR codes, e-mail log. Kept as the admin of Maintenance (OPEN_QUESTIONS #98). |
| `system_admin` | System Administrator | **Global** (all departments) | Same capabilities as `maintenance_admin`, not tied to a department. |
| `finance` | Finance | (legacy, global) | Read-only view of every non-confidential ticket, reports and CSV export. |
| `custodian` | Property Custodian | Own tickets | No global powers. Files requests and sees only their own. |

Which roles are scoped and which are global is configuration, not code: `school.department_scoped_roles` and `school.global_roles`
(`Role::departmentScopedSlugs()`, `Role::globalSlugs()`, `User::scopedDepartmentId()`).
Ticket visibility is enforced per department in Phase 4B (section 3) and claim / assign / transfer in Phase 4C
(section 5). The full role × capability table is in [ROLE_PERMISSION_MATRIX.md](ROLE_PERMISSION_MATRIX.md).

### Retired roles (seeded only under PHPUnit, still known to the code)

`department_head`, `treasurer`, `president`. The permission matrix, policies and many tests still handle them,
so they can be brought back by adding the role row and users. They are **not** created in a normal production seed.

## 2. What each role can do (`config/permissions.php`)

Change a role's powers **there**, not in the code. Read in code with `$user->can_do('capability')`. An inactive account can do nothing.

| Capability | maintenance_admin | finance | president* | requester / custodian / dept. head / treasurer |
|---|:-:|:-:|:-:|:-:|
| `view_all_tickets` (all non-confidential) | yes | yes | yes | no |
| `view_confidential` | yes | no | yes | no |
| `flag_emergency` | yes | no | yes | no |
| `clear_emergency` | yes | no | no | no |
| `send_for_authorization` | yes | no | no | no |
| `assign`, `work` | yes | no | no | no |
| `close` (capability exists; no Close button yet) | yes | no | no | no |
| `add_attachment` (to any visible ticket) | yes | no | no | no |
| `request_info` (ask requester, Waiting for User) | yes | no | no | no |
| `escalate` | yes | no | no | no |
| `view_analytics` (`/reports`) | yes | yes | yes | no |
| `export_reports` (CSV / PDF) | yes | yes | yes | no |
| `view_audit_logs` | yes | no | yes | no |
| `view_email_log` | yes | no | no | no |
| `manage_users` | yes | no | no | no |
| `manage_settings` | yes | no | no | no |
| `manage_categories` | yes | no | no | no |
| `manage_qr_codes` | yes | no | no | no |
| `manage_faq` | yes | no | no | no |
| `cancel_own`, `reopen_own` | yes | yes | yes | yes |

\* Only when the `president` role exists (see section 1).

Approve / disapprove is **not** in this matrix. It is decided by `school.workflow.authorizers` (section 5).

## 3. Who sees which ticket

One rule, written twice so lists and single pages always agree:
`MaintenanceRequest::scopeVisibleTo($user)` (queries) and `isVisibleTo($user)` (one ticket).

| Person | Sees |
|---|---|
| Requester | Their own tickets (any role, any status). |
| Assignee | Tickets assigned to them (any department). |
| Department Staff / Head / Maintenance Admin | **Only** tickets whose `assigned_department_id` is their own `users.department_id` (Phase 4B). `view_all_tickets` does not widen this. Confidential ones still need `view_confidential`. |
| System Administrator | Every ticket, including confidential (global). |
| Roles with `view_all_tickets` (finance, president) | Every non-confidential ticket (global, not scoped). |
| Roles with `view_confidential` | Also confidential tickets. Finance does **not**. |
| Department Head (if the role is used) | Non-confidential tickets of their own department (`school.department_visibility_roles`). |

**A ticket someone may not see answers `404`, not `403`**, so its existence is not revealed. This covers `{ticket}` and `{attachment}` in URLs
(route binding in `AppServiceProvider`). A confidential ticket's files follow the same rule as the ticket.

## 4. Statuses and the only allowed moves (`App\Enums\TicketStatus`)

Every status change is checked against `allowedNext()`. The model refuses a skipped step even from a direct `->update()`.

```
Submitted -> Pending Authorization -> Approved -> Assigned -> In Progress -> Work Completed -> Closed
                 |                       
                 +-> Disapproved (final)          Cancelled (final)
```

| From | May go to |
|---|---|
| Submitted | Pending Authorization, Approved, Disapproved, Waiting for User, Escalated, Cancelled |
| Pending Authorization | Approved, Disapproved, Waiting for User, Escalated, Cancelled |
| Approved | Assigned, Waiting for User, Escalated, Cancelled |
| Assigned | In Progress, Waiting for User, Escalated |
| In Progress | Work Completed, Waiting for User, Escalated |
| Work Completed | Closed, In Progress (rework), Reopened |
| Closed | Reopened only |
| Waiting for User | back to where it was (Submitted ... In Progress, Reopened), Escalated, Cancelled |
| Escalated | Pending Authorization, Approved, Disapproved, Assigned, In Progress, Reopened, Cancelled |
| Reopened | Assigned, In Progress, Waiting for User, Escalated |
| Disapproved, Cancelled | nothing (final) |

* **Finished** = Closed, Disapproved, Cancelled. They leave *My Tickets* and appear in *Ticket History*. Finished tickets take no more notes, flags, assignments or replies.
* **Pending** (counts as backlog) = every status except Work Completed, Closed, Disapproved, Cancelled.
* Number format: `MT-YYYY-00001`, restarting each year.
* Every change writes one line to `request_status_logs` (the ticket history) inside the same database transaction. All changes go through `App\Services\TicketWorkflow`.

## 5. Who does each step

| Step | Who | Rules enforced in |
|---|---|---|
| File a request (`/repairs/create`) | Roles in `school.filing_roles` (every role by default) | `TicketPolicy@create`, `StoreRepairRequestRequest` |
| "Emergency" box | Only roles with `flag_emergency` set the flag. Anyone else files a routine ticket plus a history note "Requester asked for emergency review". | `TicketWorkflow::open()` |
| 5-working-day advance notice | Everyone, except people who may flag an emergency | `school.advance_notice`, `emergency_bypasses_advance_notice` |
| Send for authorization | `send_for_authorization` | `TicketPolicy@sendForAuthorization` |
| Approve / Disapprove | Roles in `school.workflow.authorizers`. May approve straight from Submitted (`authorize_from_submitted`). Inbox: `/approvals`. | `TicketPolicy@authorize`, `User::canAuthorize()` |
| Assign / reassign | `assign`. Reassigning needs a note. Phase 4C: Head assigns anyone in the department; Staff only claim / transfer inside their own department; cross-department (target outside the ticket's department) is Head / Admin only (`cross_department_transfer`) with a reason. | `TicketActionController@assign`, `TicketWorkflow::assign()` / `claim()` / `transfer()` |
| Accept task, Mark completed (board) | `work` on the Maintenance board (`/maintenance/board`) | `MaintenanceBoardController` |
| Close | Today a ticket reaches **Closed** only when the requester presses *Confirm resolved* on a Work Completed ticket. No staff "Close" button or route exists yet (the `close` capability and `TicketWorkflow::close()` are ready for one). The model refuses Closed unless an authorization is on record (`president_id` + `president_approved_at`, filled when the ticket is approved). The *signed evidence* part of the rule is **not enforced yet**, and `workflow.closers` / `workflow.closure_requires` are declared in config but not read by any code. | `MaintenanceRequest::booted()`, `TicketWorkflow::confirmResolved()` |
| Cancel | The requester of that ticket, **before Assigned**, with a reason | `TicketWorkflow::cancelBlocker()` |
| Confirm resolved | The requester, on a Work Completed ticket (closes it, normal closure rules still apply) | `TicketPolicy@confirmResolved` |
| Reopen | The requester, within `reopen_window_days` (default 14, 0 = off) from `completed_at` / `closed_at`, with a reason | `TicketWorkflow::reopenBlocker()` |
| Ask for information | `request_info`, from Submitted ... Reopened, a question is required | `TicketWorkflow::requestInfo()` |
| Answer | The requester, in the **Answer** box (a chat message does not count) | `TicketWorkflow::respondToInfo()` |
| Escalate by hand | `escalate`, with a reason; not while Waiting for User, not twice | `TicketWorkflow::escalate()` |
| Add a history note | Requester (own), assignee, authorizers, working roles | `TicketPolicy@addNote` |
| Write in the conversation | Same people as notes. View-only roles read but get no form. | `TicketPolicy@reply` |
| Internal notes | Staff side only (assignee, authorizers, working roles). **Never the requester of the ticket**, even if they also hold a staff role. | `TicketPolicy@viewInternalNotes` |
| Add files after filing | `add_attachment`, the assignee, or the requester of their own ticket until it is finished. Only `work` users may pick the kinds *signed evidence* / *work photo*. | `TicketPolicy@addAttachment` |

## 6. Waiting for User, SLA, escalation, auto-close

* **SLA target (`due_at`)** = submitted time + hours from `/admin/settings`: routine **120 h**, emergency **24 h**, calendar hours (placeholders, confirm with the school). The requester's `needed_by` date is shown separately and does not replace it.
* **Waiting for User pauses the clock**: the ticket is not overdue while waiting and `due_at` moves later by the time waited.
* **Auto-close** (`tickets:autoclose`, hourly): a ticket unanswered for `autoclose_waiting_days` (default 7, 0 = off) ends as Cancelled with an "auto-closed" note.
* **Auto-escalate** (`tickets:escalate`, hourly): past `due_at` and Submitted / Pending Authorization / Approved / Assigned / In Progress / Reopened. Never when Waiting for User, already Escalated, or finished. The President (if present) and every active Maintenance Admin are alerted.
* An escalated ticket stays Escalated until an authorizer moves it on. There is **no dedicated "hand back" button yet** (OPEN_QUESTIONS #41).

## 7. Scheduled jobs (`routes/console.php`)

| Command | When | What it does |
|---|---|---|
| `tickets:autoclose` | hourly | closes unanswered Waiting for User tickets |
| `tickets:escalate` | hourly | escalates overdue tickets |

The Laravel scheduler must be running: `php artisan schedule:work` while testing, or a 1-minute `schedule:run` (cron on Linux, Task Scheduler on Windows).
E-mails are queued: keep `php artisan queue:work` running, or set `QUEUE_CONNECTION=sync`.

## 8. Notifications

* **In-app alerts** (bell, pop-up, chime): the page polls every `poll_seconds` (5). `FormNotifier` never alerts someone who may not see the ticket, and never the person who did the action.
* **E-mails** (`TicketMailer`, `TICKET_EMAILS=true`): never to the person who acted, never for internal notes, a message e-mail carries no message text, and a confidential ticket's e-mail shows only the number, status and a link.
* **Live refresh**: dashboards, My Tickets, Ticket History and the ticket page ask `/live` every `LIVE_REFRESH_SECONDS` (8) and reload only when nobody is typing.

## 9. Accounts

| Way in | Rule |
|---|---|
| Admin creates a user (`/admin/users`) | Temporary password, `must_change_password = true`; the user can only reach "choose a new password" until it is changed. |
| Student import (`students:import`) | Temporary password = student number, must be changed at first sign-in. Safe to re-run; it does not reset passwords. |
| Self-registration (`/register`) | **Closed by default** (`ALLOW_PUBLIC_REGISTRATION=false`). When open it always creates a Requester. With `REQUIRE_ACCOUNT_APPROVAL=true` the account waits for a Maintenance Admin (approve / reject with a reason) and cannot sign in before. |
| E-mail verification | Off by default (`REQUIRE_EMAIL_VERIFICATION=false`). Turn on after `php artisan mail:test` works. |

## 10. Preventive maintenance (removed)

The PM Scheduler, Equipment, the PM report and the Maintenance Calendar, with their `pm:generate` and `pm:notify` commands, were removed from the system. `database/migrations/2026_11_23_000001_remove_pm_scheduler.php` drops their tables and columns from an existing database.

## 11. Intentional scope decisions

* One department-wide queue; no routing between departments.
* Closing needs a recorded authorization (enforced). The planned "signed evidence" requirement and the `closers` list are declared in `config/school.php` but not enforced yet (see [LIMITATIONS.md](LIMITATIONS.md)).
* The retired roles are kept in config so they can return without code changes.
