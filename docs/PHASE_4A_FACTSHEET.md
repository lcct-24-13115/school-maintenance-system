# FAQ Phase 4A: fact sheet (what the code really does today)

Source: the repo zip you sent (`school-maintenance-system-main (10)`). Nothing in the FAQ answers (4C/4D) may say more
than this sheet says. PHP is not available where this was written, so **no test was run**; every line below comes from
reading the code.

Legend: **OK** = read in code and consistent. **DECIDE** = code, docs and tests disagree, needs your answer before 4C.
**TBD** = not read yet, will be read before the matching FAQ section is written.

---

## 0. Things you must decide first (the code disagrees with the docs)

| # | What I found | Where | Impact on the FAQ |
|---|---|---|---|
| D1 | **Who authorizes (approve / disapprove)?** `config/school.php` says `authorizers => ['maintenance_admin']`. But `docs/OPEN_QUESTIONS.md` #14, `docs/PHASE_4A_NOTES.md` and `tests/Feature/TicketActionsTest.php` (lines 116-118) all say `['president']`. The request form says "The Head of Maintenance approves" (matches the config). Your Phase 4 brief says "President authorize/disapprove". | `config/school.php` `workflow.authorizers` | "Who authorizes?", the Approvals inbox, escalation and the approver FAQ section all change. If the config is wrong, `TicketActionsTest` passes today; if the config is right, that test **fails today**. |
| D2 | **Who closes a ticket?** Config `closers => ['maintenance_admin']` (docs say Maintenance Admin + President). That key is **not read anywhere in the app**. The only code path that closes a ticket is the requester's **Confirm resolved** on a Work Completed ticket. There is no staff "Close" button and no auto-close to Closed. The auto-close for waiting tickets ends as **Cancelled**. | `TicketWorkflow::close()` only called from `confirmResolved()` | Answer must say: "you confirm the work, then it is closed". |
| D3 | `closure_requires` has `signed_evidence`, but **nothing enforces it**. The only closure rule in code: the model refuses Closed unless an authorization is recorded (`president_id` + `president_approved_at`, set only by `authorize()`). | `MaintenanceRequest::booted()` rule 3 | Do not tell anyone a signed file is required to close. |
| D4 | **PM tickets are auto-approved** with `transition()`, which never records an authorizer. So the Closed rule above would refuse to close a PM ticket (it stays Work Completed). Likely a real bug. | `PmTicketGenerator::makeTicket()` | PM FAQ will say the PM ticket ends at **Work Completed**. Keep it, and I will list this as a code issue for you (not fixed in Phase 4). |
| D5 | **Escalated is a dead end in the UI.** From Escalated nobody can approve, assign, accept or complete (those actions only accept their own statuses). `OPEN_QUESTIONS` #41 already says "hand back NOT BUILT". | `assertAuthorizableStatus`, `assignableStatuses`, `startWork` | Answer: "The office handles escalated tickets; there is no button for you." |
| D6 | The confidential tick-box says only "you, the Head of Maintenance and the assigned staff" can see it. In code the **President also sees confidential tickets** (`view_confidential`). | `repairs/create.blade.php`, `config/permissions.php` | FAQ will list the real viewers. Form text is probably wrong. |
| D7 | `OPEN_QUESTIONS` #7 says self-registration is open. Code and `.env.example` default: **closed** (`/register` returns 404). | `config/school.php`, `RegisteredUserController` | FAQ says: accounts are created by the office. |

If D1 is "President authorizes": the fix is one line in `config/school.php` (`authorizers` and `closers`), and I would also
tell you to update the form text. If D1 is "Maintenance Admin authorizes": `OPEN_QUESTIONS.md` #14/#16, `PHASE_4A_NOTES.md`
and `TicketActionsTest` need updating. Either way, **I will write answers to match the final decision**.

---

## 1. Roles (OK)

Real role slugs (keys of `config/permissions.php`, also the only valid FAQ audiences):
`requester`, `department_head`, `treasurer`, `custodian`, `maintenance_admin`, `finance`, `president`.
There is **no separate "admin" or "staff" role**: Maintenance Admin = the maintenance office staff and the "admin".

| Role | Real powers today |
|---|---|
| requester, department_head, treasurer, custodian | File tickets; see own tickets; cancel / reopen / confirm resolved / answer info requests on **own** tickets. Department head also sees non-confidential tickets of own department. All four are view-only on other people's tickets (no notes, no replies). |
| maintenance_admin | Sees all incl. confidential; send for authorization; assign; accept / complete (board); flag and clear emergency; ask info; escalate; notes, replies, internal notes; reports + CSV/PDF; users; settings; categories; QR codes; PM schedules/equipment/calendar/PM report; audit log; FAQ manager. (Plus authorize / disapprove **if D1 = maintenance_admin**.) |
| finance | Sees all **non-confidential** tickets; Reports + exports; PM report only (no PM schedules, no calendar). |
| president | Sees all incl. confidential; may flag emergency; Reports + exports; audit log; PM schedules and PM calendar **read-only**; PM report. (Approve / disapprove **only if D1 = president**.) |

## 2. Getting started (OK unless noted)

- Sign-in page: e-mail + password. After **5** wrong tries (same e-mail and device) sign-in is locked for **15 minutes** (env `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`).
- Accounts are made by the office (Users page). `/register` is closed by default (D7). Allowed e-mail domain default `lcctanauan.edu.ph`.
- First sign-in with a temporary password: forced to **Choose a new password** page before anything else. New password must differ from the temporary one. Minimum length is Laravel's default, **8 characters** (no custom rule found).
- Forgot password: link on the sign-in page (`/forgot-password`), e-mail with a reset link.
- E-mail verification: **off by default** (`REQUIRE_EMAIL_VERIFICATION=false`). When on, an unverified account only sees the "verify your e-mail" page. Accounts made by the office are already verified.
- Session lasts **120 minutes** (`SESSION_LIFETIME`); after that you must sign in again and the page may say "419 Page Expired".
- Menu names: **My Tickets**, **Ticket History**, **New repair request** (only for roles in `filing_roles`: all 7), **Approvals** (authorizers only), **Notifications**, **Help & FAQ**; Administration group: **Reports**, **PM report**, **Users**, **Settings**, **Categories**, **FAQ manager**, **PM calendar**, **PM schedules**, **Equipment**, **QR codes**, **Audit log** (each only for roles with the capability).
  *TBD: exact visible label of the PM calendar / PM schedules / PM report links (I saw the route names, not the text).*

## 3. Sending a repair request (OK)

Form fields (page `/repairs/create`, alias `/tickets/create`): From (Department/Office), Request type (optional: Materials / Equipment / Supplies; when chosen, an **Items needed** block appears, max 20 items), Maintenance category (required), Where is it? (building/room), Job Description (max 1000), Reason for Request (max 2000), Priority, Date needed, Photos & documents (optional), "Mark as confidential", button **SEND REQUEST**.

- Priority tiles: **First Come First Served** or **Emergency**. Only people with `flag_emergency` (Maintenance Admin, President) really flag an emergency. For everyone else the tile reads **Ask for emergency review**: the ticket is filed as **routine**, and Maintenance sees your request and reason in the history.
- **Date needed**: at least **5 working days** from today (weekends skipped, school holidays are **not** skipped). The real emergency flag skips this rule.
- Files: up to **5 files** per upload, **5 MB** each. Allowed: **jpg, jpeg, png, webp, pdf, docx, xlsx**. The type is checked by content, so renaming a file does not work. Names such as `file.php.jpg` are refused.
- Confidential: visible to the requester, the assigned staff, Maintenance Admin and President (D6). Finance and department heads cannot see it. Its e-mails show only ticket number, status and a link.
- After sending: you get a ticket number like **MT-2026-00001**, and Maintenance is alerted.
- QR shortcut: Maintenance Admin makes QR codes that open the **New repair request** form, the login page or any link; a phone must be able to open the site address.

## 4. Following your request (OK; wording of statuses from `TicketStatus::label()`)

Statuses and what they mean for the person:

| Status | Meaning (as built) |
|---|---|
| Submitted | Received, nobody has acted yet. |
| Pending Authorization | Maintenance sent it to the authorizer's queue. |
| Approved | Authorized and recorded ("Authorized by"). Waiting to be assigned. |
| Assigned | A maintenance staff member was chosen. |
| In Progress | Staff accepted the task and is working. |
| Work Completed | Work is done. The requester can **Confirm resolved** (closes it) or **Reopen**. |
| Closed | Finished. May still be reopened inside the window. |
| Disapproved | Refused, a reason is required. Final. |
| Waiting for User | Staff asked you a question. You answer in the **Answer** box. Target time pauses. |
| Escalated | Handed up (by hand with a reason, or automatically when past the target date). See D5. |
| Cancelled | Cancelled by the requester, or auto-closed after no reply. Final. |
| Reopened | Requester reopened a Work Completed/Closed ticket. Goes back to work. |

- **Who authorizes**: see D1. A ticket may be authorized straight from Submitted (`authorize_from_submitted = true`); Maintenance can also send it to Pending Authorization first.
- **Cancel**: only your own ticket, only **before it is assigned**, a reason is required.
- **Reopen**: only your own Work Completed/Closed ticket, within **14 days** (setting `reopen_window_days`, 0 = off) counted from completion/closing, a reason is required.
- **Waiting for User**: the Answer box on the ticket page is the only thing that resumes the ticket; a normal message in the conversation does **not**. After **7 days** with no answer the ticket is auto-cancelled (setting `autoclose_waiting_days`, 0 = off; checked hourly).
- **Target (due) date**: submission time + **120 hours** routine / **24 hours** emergency, **calendar hours** (settings `sla_hours_routine`, `sla_hours_emergency`). It is Maintenance's target, not a promise; it moves later by the time spent in Waiting for User. "Date needed" is separate and does not change it.
- **Notifications**: bell, pop-up and chime in the page (polls every 5 s), plus queued e-mails: new ticket to Maintenance Admins; sent for authorization to the authorizer; approved/disapproved to requester + Maintenance; assigned to assignee + requester; started / completed / info needed to the requester; answered / reopened to Maintenance + assignee; closed/cancelled to requester, Maintenance, assignee; escalated to authorizer + Maintenance; new message to the other side. The person who did the action is never e-mailed. Internal notes are never e-mailed. E-mails need a queue worker running.
- Pages refresh by themselves about every **8 seconds** (not while you are typing).
- Conversation: requester, assignee and working roles can write; text max 2000 chars and/or up to 5 files; a finished ticket takes no more replies. Internal notes: staff only, text only, never seen by the requester.

## 5. Maintenance staff (OK)

- **Assign** (Approved → Assigned): dropdown of active Maintenance Admins; a **reassignment needs a note**.
- Board `/maintenance/board` (three columns: new tasks / doing / done in the last 7 days): **ACCEPT TASK** (Approved, Assigned, Reopened → In Progress; refused if assigned to someone else or still not authorized) and **MARK COMPLETED** (→ Work Completed). PM tickets are completed on their own page (section 7).
- Internal notes, normal notes, **Ask for information** (needs a question), **Escalate** (needs a reason; not while Waiting for User; not twice).
- Escalation alerts follow D1 (today: Maintenance Admins; President only if she is an authorizer). Automatic escalation: every hour, open tickets past their target date.
- Closing: see D2/D3.

## 6. Approvers and management (D1 decides most of this)

- **Approvals** inbox `/approvals`: tickets in Pending Authorization plus Submitted; emergencies first, then oldest. Approve (remarks optional, optional signed file stored as signed evidence) or Disapprove (reason required). Everyone else gets 403.
- Department head: sees non-confidential tickets of own department, view-only. Treasurer: no extra visibility (view own tickets only). **Not confirmed with the client**; keep "The office may change this".
- Finance: all non-confidential tickets, Reports, exports, PM report. No PM schedules or calendar.

## 7. Preventive maintenance (OK)

- A **PM schedule** (Administration → PM schedules; Maintenance Admin changes, President looks only) repeats a job: title, facility, optional equipment, category, assignee, priority, frequency (daily, weekly, monthly, quarterly, yearly, custom every N days), start / end date, **lead time days**, **basis fixed / floating**, optional **checklist**.
- A ticket is created automatically **lead-time days before the due date** (job runs daily at 01:00, Manila time). **Ticket now** makes it immediately. If days were missed, only **one** ticket is made.
- Title looks like "[PM] Aircon Cleaning - Q4 2026". The PM ticket is created already **Approved** (config `pm.auto_approve`), and **Assigned** when the schedule has an assignee. See D4 for closing.
- **Fixed**: dates always follow the original calendar. **Floating**: the next date is counted from the day the work was really completed.
- **Complete PM** form (on the ticket page, only when In Progress): completed date (not in the future, not before the ticket was made), technician (active maintenance staff), notes, checklist. An **unticked checklist line needs a note** (`require_note_for_unchecked = true`). Result: Work Completed.
- Reminders: 3 days before (`PM_REMINDER_DAYS`); overdue marking; supervisor (President) alerted after 2 days overdue (`PM_ESCALATE_AFTER_DAYS`); daily 07:00.
- **PM calendar** `/maintenance-calendar` (Maintenance Admin full, President look only): colours **Blue** upcoming, **Yellow** open / in progress, **Green** completed, **Red** overdue, **Grey** cancelled/disapproved. Filters: month, facility, equipment, category, assigned to, status.
- **PM report** `/pm-reports` (Maintenance Admin, President, Finance): on time = done on or before the PM due date; late = done after; overdue = still open past due; **on-time rate = on time / (on time + late + overdue)**; not-due-yet and cancelled are left out; CSV (all rows) and PDF (latest 300); every download is logged. Dashboard cards (overdue, due in 7 days, on-time rate over last 90 days) for President and Finance.

## 8. Reports and records (partly TBD)

- **Reports** `/reports` (Maintenance Admin, Finance, President): only tickets the person may see; CSV export and PDF; **every export is written to the audit log**.
- Definitions already documented in `OPEN_QUESTIONS` #53-60 (first response, resolution rate, backlog, Needs attention, stale = 3 days). *TBD: I will read `ReportController` and `reports/index.blade.php` to confirm filter names and button labels before writing.*
- **Audit log** (Maintenance Admin, President): read-only; filters user, action, dates, search.

## 9. Troubleshooting (OK unless noted)

- **Cannot see a ticket**: a ticket you may not see answers **404**, not 403. You see your own, tickets assigned to you, plus whatever your role allows. Confidential ones are hidden from most roles.
- **403 Forbidden**: you are signed in but your role does not have that page or button. There is **no custom 403 page**; it is the standard Laravel page. (The Approvals page text: "You do not have access to this page.")
- **419 / session expired**: sign in again; then repeat. Long forms: copy your text first.
- **No e-mail**: check spam; e-mails are queued (need a worker); `TICKET_EMAILS=false` turns them off; you are never e-mailed for your own action; confidential tickets send a short e-mail.
- **Upload fails**: see section 3 (type, 5 MB, 5 files; renamed or double-extension files are refused; the browser forgets the files after a form error, pick them again).
- **Locked sign-in**: wait 15 minutes. **Temporary password**: you must change it first.
- **Cannot answer / cancel / reopen**: only the person who filed it; cancel only before assigned; reopen only inside the window; answer only while Waiting for User.
- **Stale ticket message** "This ticket was just updated by someone else": reload and retry.

---

## 10. Notes for 4B-4F (so nothing is missed later)

- `FaqController` filters by **exact topic name** (`?topic=Following your request`). The help links in 4E must use the exact topic text, and the topic only shows if the person may read at least one entry of it.
- Current starter file has **8 sample rows**; `FaqSeeder` uses `firstOrCreate` (add only). No `source` column exists yet.
- `FaqAdminController::update()` fills the model and saves, so for 4B it must also set `source = 'custom'` when an admin edits a starter row. `store()` and `duplicate()` rows are custom by default.
- Valid audiences for the 4F test: the seven slugs in section 1 (the model already has `Faq::roleSlugs()`).
- Public entries must have audience `['all']` (model rule).
- The help links go on: `repairs/create`, `tickets/show`, `admin/pm-schedules/index`, `maintenance/calendar`, `pm-reports/index`, `reports/index`. Each is visible only if the page itself is.
- No PHP/composer in the authoring environment, so tests in 4F will again be **written but not run by me**. You run `php artisan test --filter=FaqContentTest`.

## 11. Updates this phase will make to `docs/OPEN_QUESTIONS.md` (after D1-D7)

Add or fix rows: authorizer (#14, #16), closing path (D2), signed evidence not enforced (D3, #8), PM ticket close (D4), escalated hand-back (D5, #41), confidential tick-box text (D6), registration default (D7, #7), password minimum 8, session 120 min.
