# Test plan (automated + manual)

> **Status: nothing below has been run yet.** This plan was written by reading the code, in an environment with no PHP, Composer or browser.
> Run section 1 first and fix anything red before the manual cases. Write the result of each case in the *Result* column (Pass / Fail + date) and keep the evidence (screenshot, ticket number).

## 0. Prepare

```powershell
cd C:\laragon\www\school-maintenance-system
copy .env.example .env            # only the first time
php artisan key:generate          # only the first time
php artisan migrate:fresh --seed  # this ERASES the local database
npm install ; npm run build       # or: npm run dev
php artisan serve                 # http://127.0.0.1:8000  (localhost gives a working service worker)
```

`migrate:fresh --seed` creates the staff accounts only (`maintenance@`, `custodian@`, `finance@lcctanauan.edu.ph`, password `password`) plus categories, departments, facilities and the PM starter schedules. **It creates no students and no tickets.**
Make the people you need in *Admin -> Users*:

| Label in this plan | How to get it |
|---|---|
| **ADMIN** | `maintenance@lcctanauan.edu.ph` (Head of Maintenance) |
| **FIN** | `finance@lcctanauan.edu.ph` |
| **CUST** | `custodian@lcctanauan.edu.ph` |
| **R1**, **R2** | two Student (requester) accounts made by ADMIN (temporary password; first sign-in forces a change) |
| **STAFF** (optional) | a second Maintenance Admin account, to test reassignment |

Mail: leave `MAIL_MAILER=log` (e-mails are written to `storage/logs/laravel.log`) unless a case says otherwise.

## 1. Automated tests

```powershell
php artisan test                         # everything
php artisan test --filter=Pm             # PM Scheduler only
php artisan test --filter=Faq            # Help & FAQ only
php artisan test --filter=PwaTest        # PWA files
vendor\bin\pint --test                   # code style (omit --test to fix)
```

| Area | Test files |
|---|---|
| Tickets, workflow | `TicketFoundationTest`, `TicketActionsTest`, `TicketRequesterActionsTest`, `TicketInfoRequestTest`, `TicketEscalationTest`, `TicketHistoryTest`, `SettingsAndDueDateTest` |
| Access / privacy | `TicketAccessTest`, `TicketInternalNoteTest`, `FaqLeakAuditTest`, `PmAccessTest` |
| Conversation, files | `TicketReplyTest`, `AttachmentTest`, `AttachmentViewerTest`, `FilePickerPreviewTest` |
| Accounts | `AccountApprovalTest`, `UserAdminTest`, `AvatarTest`, `ProfileTest`, `Auth/*` |
| Admin pages | `CategoryAdminTest`, `QrCodeTest`, `AuditLogTest`, `AuditLoggerTest`, `EmailLogTest`, `TicketEmailTest`, `MailTestCommandTest` |
| Reports, live | `ReportTest`, `ReportOverviewTest`, `LiveRefreshTest` |
| PM Scheduler | `PmScheduleTest`, `PmScheduleBulkTest`, `PmTicketGeneratorTest`, `PmChecklistTest`, `PmCompletionTest`, `PmNotifyTest`, `MaintenanceCalendarTest`, `PmCalendarPageTest`, `PmReportTest`, `EquipmentHistoryTest`, `PmEndToEndTest` |
| Help & FAQ | `FaqModelTest`, `FaqPageTest`, `FaqAdminTest`, `FaqContentTest`, `FaqCacheTest`, `FaqFeedbackTest`, `FaqInsightsTest`, `FaqSettingsTest`, `FaqAccessibilityTest`, `HelpLinkTest`, `FaqEndToEndTest` |
| UI, PWA | `UiPolishTest`, `PwaTest`, `Unit/TicketStatusBadgeTest` |

Known items to check in this run (details in [LIMITATIONS.md](LIMITATIONS.md)):

* `TicketActionsTest::test_the_workflow_config_has_the_documented_defaults` expects `authorizers = ['president']`; the config now says `maintenance_admin`. Expect a failure until the test or the config is aligned.
* `TmpSeedDebugTest` is a throw-away debug test (prints to STDERR). Delete it.
* Tests that need the retired roles (president, department head, treasurer) rely on the seeders' *testing* branch, so they pass only under PHPUnit.

## 2. Install and data safety

| # | Steps | Expected | Result |
|---|---|---|---|
| 2.1 | `php artisan migrate:fresh --seed` on an empty database | No error; 4 roles; ADMIN / FIN / CUST exist | |
| 2.2 | Run `php artisan migrate` a second time | "Nothing to migrate" | |
| 2.3 | Open `/.env`, `/storage/app/private/`, `/vendor/autoload.php` in the browser | 404 / 403, never file contents | |
| 2.4 | `/up` | Responds OK (health check) | |
| 2.5 | `APP_DEBUG=false`, trigger an error (e.g. `/tickets/99999` signed in) | Plain 404 page, no stack trace | |

## 3. Authentication and sessions

| # | Steps | Expected | Result |
|---|---|---|---|
| 3.1 | Sign in as R1 with the temporary password | Forced to *choose a new password*; every other page redirects there until done | |
| 3.2 | On that page, reuse the temporary password as the new one | Refused ("different") | |
| 3.3 | Wrong password 4 times for one e-mail | Each message shows attempts left; the 5th locks sign-in for 15 minutes | |
| 3.4 | While locked, type the **right** password | Still refused with the "try again in N minutes" message | |
| 3.5 | Audit log after 3.3 | An `account_locked` row exists | |
| 3.6 | Wrong e-mail / empty fields | Neutral error or inline field messages, no server error | |
| 3.7 | Sign out, press Back | Login page; no private page shown | |
| 3.8 | ADMIN deactivates R2 while R2 is signed in; R2 clicks anything | Back at login | |
| 3.9 | Forgot password (`MAIL_MAILER=log`) | Reset link is in `laravel.log`, **not** on screen; link works once; link older than `PASSWORD_RESET_MINUTES` is refused | |
| 3.10 | `/register` with `ALLOW_PUBLIC_REGISTRATION=false` | 404 and no Register links | |

## 4. Registration and approval (set `ALLOW_PUBLIC_REGISTRATION=true`, `REQUIRE_ACCOUNT_APPROVAL=true`)

| # | Steps | Expected | Result |
|---|---|---|---|
| 4.1 | Register with an address outside `SCHOOL_EMAIL_DOMAIN` | Rejected | |
| 4.2 | Register with a valid school address | Account is Pending; ADMIN gets a "new registration" e-mail / alert | |
| 4.3 | Sign in as the pending user with the **right** password | Message says it waits for approval | |
| 4.4 | Same, with the **wrong** password | Normal "credentials do not match" (no status leak) | |
| 4.5 | ADMIN approves; user signs in | Works; role is Requester only | |
| 4.6 | ADMIN rejects another registration with a reason | User sees the reason after the right password | |

## 5. Filing a ticket (R1)

| # | Steps | Expected | Result |
|---|---|---|---|
| 5.1 | `/repairs/create` with category, location, description, reason and a `needed_by` 5 working days ahead; add a JPG | Ticket `MT-YYYY-0000N`, status Submitted; ADMIN gets an alert | |
| 5.2 | `needed_by` only 2 days ahead | Refused (5-working-day rule) | |
| 5.3 | Tick *Emergency* as R1 | Ticket is **routine**; history says "Requester asked for emergency review" | |
| 5.4 | Choose Materials, add 2 items; then switch type to None | Items block appears / items are dropped | |
| 5.5 | Pick a category that does not match the request type | Refused | |
| 5.6 | Upload `shell.php.jpg`, a PNG renamed `.jpg`, a 6 MB photo, 6 files | Each refused with a clear message; no ticket created | |
| 5.7 | Tick *Mark as confidential* | Lock badge on the ticket | |
| 5.8 | Double-click Send | Only one ticket | |

## 6. Access control

| # | Steps | Expected | Result |
|---|---|---|---|
| 6.1 | R2 opens `/tickets/<R1's id>` | 404 | |
| 6.2 | R2 opens `/attachments/<R1's file id>` | 404 | |
| 6.3 | R1 opens `/admin/users`, `/admin/settings`, `/admin/audit-logs`, `/reports`, `/pm-reports`, `/maintenance-calendar`, `/approvals` | 403 each | |
| 6.4 | FIN opens `/admin/users`, `/admin/pm-schedules`, `/maintenance-calendar` | 403 | |
| 6.5 | FIN opens `/reports` and `/pm-reports` | Opens | |
| 6.6 | FIN looks for R1's **confidential** ticket (list and URL) | Not in the list; URL gives 404 | |
| 6.7 | CUST opens *Tickets* | Only their own (empty state if none) | |
| 6.8 | Logged out, open `/attachments/<id>` and `/tickets/<id>` | Redirect to login | |
| 6.9 | Open `/storage/ticket-attachments/...` | 404 (private disk) | |
| 6.10 | POST an action with the CSRF token removed (dev tools) | "Page expired" (419) | |

## 7. Workflow (ADMIN works R1's ticket)

| # | Steps | Expected | Result |
|---|---|---|---|
| 7.1 | ADMIN opens the ticket; *Approve* (or *Send for authorization* then *Approve*) | Status Approved; "Authorized by" recorded; R1 alerted | |
| 7.2 | *Assign* to self | Status Assigned; history line | |
| 7.3 | Reassign to STAFF without a note | Refused; with a note it works | |
| 7.4 | Maintenance board: **Accept task** | In Progress | |
| 7.5 | **Mark completed** | Work Completed | |
| 7.6 | Look for a staff "Close" button on a Work Completed ticket | None exists today (known gap); the ticket closes through 7.7 | |
| 7.7 | R1 on a Work Completed ticket: *Confirm resolved* | Closes; history says "Confirmed resolved by the requester."; a ticket that was never authorized refuses to close | |
| 7.8 | R1 *Reopen* a Closed ticket without / with a reason; then after `reopen_window_days` (Settings) | Needs a reason; refused outside the window; clears `closed_at` | |
| 7.9 | R1 cancels a Submitted ticket with a reason; tries on an Assigned one | Allowed / not offered | |
| 7.10 | ADMIN *Ask for information* with a question | Waiting for User; R1 alerted and e-mailed | |
| 7.11 | R1 answers in the **Answer** box | Back to the earlier status; R1 only writing in the conversation does **not** resume it | |
| 7.12 | Leave a Waiting for User ticket > `autoclose_waiting_days`; run `php artisan tickets:autoclose` | Ends as Cancelled with the auto-closed note | |
| 7.13 | Set a ticket's `due_at` in the past; run `php artisan tickets:escalate` | Escalated; ADMIN alerted; Waiting for User tickets are skipped | |
| 7.14 | ADMIN flags emergency with a reason (try without one), then clears it | Reason is required; flag, reason and history line appear and disappear | |
| 7.15 | Try to move a finished ticket (note, assign, file) | Refused | |

## 8. Conversation, internal notes, attachments

| # | Steps | Expected | Result |
|---|---|---|---|
| 8.1 | ADMIN posts an **internal note** | R1 sees nothing: not on the page, in the alerts or e-mails | |
| 8.2 | ADMIN public reply; R1 replies | Both visible; the other side is alerted; the e-mail has no message text | |
| 8.3 | Message containing `<script>alert(1)</script>` | Shown as text, nothing runs | |
| 8.4 | R1 adds a PDF after filing | Appears with icon, size, uploader; history line | |
| 8.5 | Open an image, a PDF, a `.docx` | Image / PDF inline; docx shows a text preview; Download works | |
| 8.6 | ADMIN uploads *Signed evidence* / *Work photo* | Kind badge shown; R1 cannot choose those kinds | |
| 8.7 | Remove a file from a ticket | Gone; `attachment_deleted` in the audit log | |

## 9. Lists, live refresh, reports

| # | Steps | Expected | Result |
|---|---|---|---|
| 9.1 | Tickets: search by partial ticket number, a word, requester name; each filter; page 2 | Correct rows, filters kept, *Clear filters* resets | |
| 9.2 | *Ticket History* vs *My Tickets* | Closed / Cancelled / Disapproved only in History; a reopened ticket returns to My Tickets | |
| 9.3 | Two browsers: change a ticket in one | The other updates within ~8 s, unless a form has unsent text (then a "tap to refresh" bar) | |
| 9.4 | `/reports` as ADMIN, FIN | Numbers match the ticket lists for the same dates; FIN excludes confidential tickets | |
| 9.5 | Download CSV with a ticket whose description starts with `=1+1` | The cell starts with `'`; export is in the audit log | |
| 9.6 | Phone width (360 px) | Cards instead of tables, nothing scrolls sideways | |

## 10. PM Scheduler (ADMIN unless noted)

| # | Steps | Expected | Result |
|---|---|---|---|
| 10.1 | `/admin/pm-schedules` -> new schedule (e.g. every 1 month, lead 3 days, next due in 2 days) | Saved; shows in the list | |
| 10.2 | *Ticket now* on it; run `php artisan pm:generate` again | One ticket for that due date, never two | |
| 10.3 | Complete the PM ticket with the *Complete PM* form (date, technician, checklist; note for unchecked items) | Work Completed; `last_completed_date` and next due date updated (fixed vs floating) | |
| 10.4 | `/maintenance-calendar` | Blue = coming, yellow = open, green = done, red = overdue, grey = cancelled; filters and month link work | |
| 10.5 | `/pm-reports` + CSV + PDF | On-time rate = on time / (on time + late + overdue); exports audited | |
| 10.6 | President (if the role exists): schedules and calendar | Visible without change buttons; direct POST gives 403 | |
| 10.7 | Pause, duplicate, bulk-pause, delete a schedule | Each works and is audited (`pm_schedule_changed`) | |
| 10.8 | `php artisan pm:notify` | Reminders / overdue / supervisor alerts per `school.pm` | |

## 11. Admin pages

| # | Steps | Expected | Result |
|---|---|---|---|
| 11.1 | Settings: change SLA hours, reopen window, auto-close days | Saved; new tickets use the new SLA; audited | |
| 11.2 | Categories: add, edit, hide, reorder, delete | Hidden ones leave the form; audited | |
| 11.3 | Users: add, deactivate, change role, reset password | Temporary password shown once; user forced to change it | |
| 11.4 | QR codes: Login / New request / custom link; print; download SVG | Scans and opens; Register option only while registration is open | |
| 11.5 | E-mail log with a deliberately wrong SMTP host | Row shows Failed with the reason; *Send again* is audited | |
| 11.6 | Help & FAQ: manager (ADMIN), reading (each role), public page without sign-in | Public sees only public + "for everybody" entries; votes work; searches with no result are counted | |

## 12. PWA (see [PWA.md](PWA.md))

| # | Steps | Expected | Result |
|---|---|---|---|
| 12.1 | Chrome DevTools -> Application -> Manifest | No errors; 4 icons visible | |
| 12.2 | Application -> Service Workers | `sw.js` activated | |
| 12.3 | Network -> Offline, reload a signed-in page | "You're offline" page, no private data | |
| 12.4 | Cache Storage after browsing signed in | Only css / js / images / fonts; no HTML pages, attachments or avatars | |
| 12.5 | Android Chrome: *Install app* button / menu | Installs; opens standalone at the dashboard | |
| 12.6 | iPhone Safari: Share -> Add to Home Screen | Icon and name correct | |
| 12.7 | `http://school-maintenance-system.test` without SSL | No service worker (expected; needs HTTPS or localhost) | |
