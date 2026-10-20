# Phase 4A — Authorization, assignment, emergency flags

Front half of the lifecycle: **Submitted → Pending Authorization → Approved → Assigned**, all from the UI, with a
history line for every step. Work execution (accept / complete / rework) and formal closure are **Phase 4B**.

## What was built

- **`school.workflow` config** (`config/school.php`, every value `// CONFIRM WITH CLIENT`):
  `authorizers => ['president']`, `closers => ['maintenance_admin', 'president']`,
  `closure_requires => ['authorization', 'signed_evidence']`, `authorize_from_submitted => true`.
  - `authorizers` and `authorize_from_submitted` are **live now**. `closers` and `closure_requires` are only
    declared; Phase 4B enforces them.
  - `TicketWorkflow::authorize()` and `disapprove()` no longer call `isPresident()`. They use
    `User::canAuthorize()` (role in `authorizers`). Error style is unchanged:
    `Only the School President can authorize a ticket.` (the role name comes from the roles table).
  - `president_id` / `president_approved_at` / `president_remarks` stay as the recorded authorization evidence;
    the UI calls them **Authorized by**.
- **Action panel** on the ticket page (`tickets/_actions.blade.php`, logic in `App\Services\TicketActions`).
  An action is shown only if the status allows it **and** the user has the capability. Controllers check again
  (`TicketPolicy`) and show `DomainException` messages as a red flash box.
  - Admin: Send for authorization · Assign / Reassign (dropdown of **active** maintenance staff with their open-ticket
    count; a reassignment needs a note)
  - Authorizer: Approve (remarks optional, optional signed file saved as `signed_evidence`) · Disapprove (reason required)
  - Emergency: Flag (reason required) · Clear (note optional). Not allowed on Closed / Disapproved.
  - Anyone involved: Add note (not on Closed / Disapproved)
- **Approvals inbox** `GET /approvals` (authorizers only, others get 403): Pending Authorization, plus Submitted when
  `authorize_from_submitted`; emergencies first, then oldest `submitted_at`. Approve / Disapprove inline. "Approvals"
  link in the menu; the President dashboard card now counts and links to the inbox.
- **Concurrency**: `TicketWorkflow::apply()` re-reads the ticket with `lockForUpdate()` inside the transaction and
  compares it with what the person had on screen (status, assignee, emergency flag). If it changed, the action is
  refused with *"This ticket was just updated by someone else. Reload the page…"*.
- New capability `send_for_authorization` (Maintenance Admin) in `config/permissions.php`.
- Dept heads, treasurer, custodian and finance stay **view-only** (no action buttons, no notes).

## New routes

`POST /tickets/{ticket}/send-for-authorization | approve | disapprove | assign | emergency | emergency/clear | notes`,
`GET /approvals`. Hidden ticket = 404, visible but not allowed = 403, wrong status = flash error.

## Behaviour changes worth knowing

- `authorize()` / `disapprove()` now refuse a ticket that was already decided ("cannot move from … to …").
  Before, authorizing an already Approved ticket silently wrote a second authorization line.
- `clearEmergency()` and `addNote()` now refuse Closed / Disapproved tickets (new guards).
- `authorize()` takes an optional 4th argument (files). Existing calls are unaffected.
- No notifications are sent from the new controllers (Phase 5 hooks into `TicketWorkflow`).
- The "approve via tinker" note in `PHASE1_NOTES.md` is left for Phase 4B, which replaces it with the full flow.

## Tests

New file `tests/Feature/TicketActionsTest.php` (39 tests): every action (allowed role, other roles 403/404, wrong
status error), empty reason/note rejection, queue order after flag/clear, terminal tickets, panel visibility, inbox
order + access, config-driven authorizers, `authorize_from_submitted` off, and the concurrency check.

**Existing tests changed: none.** `TicketFoundationTest` and `TicketAccessTest` were re-read against the new rules:
`test_only_the_president_can_authorize` still sees `Only the School President…`, and every test that authorizes
does so from Submitted with the President (allowed by the defaults). The Closed-ticket rule/seeder tests are
Phase 4B (they change there).

> The tests were written without being able to run PHP in the authoring environment. Run `php artisan test` first.

## Acceptance (browser, 3 accounts, password `password`)

1. `requester@lcctanauan.edu.ph` files a repair request.
2. `maintenance@lcctanauan.edu.ph` opens it → **Send for authorization**.
3. `president@lcctanauan.edu.ph` → **Approvals** → Approve (try Disapprove with an empty reason first: it is refused).
4. `maintenance@…` → **Assign** to *Test Maintenance Staff 2* (note the open-ticket counts) → status **Assigned**.
5. Ticket history shows 4 lines. Try Flag / Clear emergency and watch the queue order on `/tickets`.
