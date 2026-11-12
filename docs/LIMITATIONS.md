# Known limitations and open items (honest status)

Read this before a demo or before real use. Items are grouped, but the numbers stay stable because other docs point at them.

## A. Things that need your attention first

1. **Not executed.** These docs (`ROLES_AND_WORKFLOW`, `SECURITY_AND_PRIVACY`, `TEST_PLAN`, `LIMITATIONS`, `PWA`) were written by reading the code, with no PHP, Composer, database or browser available. No test, migration, page or script was run for them. The phase notes say the same about several earlier phases. First action: `php artisan migrate:fresh --seed`, then `php artisan test`, then the manual cases in [TEST_PLAN.md](TEST_PLAN.md). Send the exact error text of anything that fails.
2. **Config, tests and older docs disagree about who authorizes.** `config/school.php` now has `workflow.authorizers = ['maintenance_admin']` and `workflow.closers = ['maintenance_admin']`, matching the production roles (the President, Department Head and Treasurer roles are seeded only under PHPUnit). But `OPEN_QUESTIONS.md` #14 / #16 and `TicketActionsTest::test_the_workflow_config_has_the_documented_defaults` still expect `['president']` and `['maintenance_admin', 'president']`. Expect that test to fail until you either update it (and the two rows) or restore the config. Decide with the school who the final signatory really is.
3. **Closing a ticket is only half built.**
   * The only way a ticket reaches *Closed* today is the requester's **Confirm resolved** on a Work Completed ticket. No staff "Close" route or button exists, although the `close` capability and `TicketWorkflow::close()` are in place.
   * The model refuses *Closed* unless an authorization is on record (`president_id` + `president_approved_at`).
   * The planned "signed evidence" requirement (`workflow.closure_requires`) and the `workflow.closers` list are in config but **no code reads them**. `signed_evidence` files can be uploaded and are labelled, but nothing checks for one.
   * A Work Completed ticket that the requester never confirms stays there. There is no automatic close for it.
4. **Escalated tickets have no "hand back" button** (OPEN_QUESTIONS #41). An authorizer can move one on through the normal status rules, but there is no dedicated screen.
5. **Seeded staff passwords are `password`** (`UserSeeder`) and are not forced to change. Change them before any real use ([SECURITY_AND_PRIVACY.md](SECURITY_AND_PRIVACY.md) section 2).
6. **`TmpSeedDebugTest.php`** is a throw-away debug test. Delete it.

## B. Decisions still waiting for the school

7. Every placeholder is marked `// CONFIRM WITH CLIENT` and listed in [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) (97 rows). The ones that change behaviour most: final signatory (#14), who may close (#16), SLA hours and calendar-vs-working hours (#21), reopen window (#25), auto-close days (#30), e-mail recipients (#42), PM visibility (#67-69), PM on-time definition (#70).
8. **SLA numbers are placeholders**: routine 120 h, emergency 24 h, calendar hours. The reopen window (14 days) and auto-close (7 days) are also provisional.
9. **No retention or disposal rule** for tickets, attachments, audit logs, e-mail logs, FAQ votes or "no answer" searches (OPEN_QUESTIONS #9, #97). Nothing deletes them.
10. **Data Privacy Act:** controls exist, compliance is **not** certified. A privacy notice, retention policy, DPO contact and breach procedure still have to be written by the school ([SECURITY_AND_PRIVACY.md](SECURITY_AND_PRIVACY.md) section 4).

## C. Behaviour you may notice

11. **Retired roles** (`department_head`, `treasurer`, `president`) still exist in `config/permissions.php`, policies and tests, but a normal seed does not create them. Anything aimed at the President finds nobody unless that role is brought back. Most important: `school.pm.supervisor_roles` is still `['president']`, so the PM "overdue, tell the supervisor" alert (`pm:notify`) has no recipient in a normal install. Change it to `['maintenance_admin']` (or whoever supervises) if that alert is wanted.
12. **Accounts are made by an admin.** Staff are created by a Maintenance Admin; students come from `students:import` (temporary password = student number) or from an approved registration.
13. **Self-registration is off by default** and, when on, needs admin approval and (optionally) a school e-mail address.
14. **The old `repair_requests` table** is left in the database and no longer receives new records; the repair form files tickets in `requests` (Phase 1 note).
15. **Live updates are polling, not push.** Alerts poll every 5 s, pages check `/live` every 8 s. Laravel Reverb is described in `.env.example` but not installed (`BROADCAST_CONNECTION=log`).
16. **A page never reloads while someone is typing**; a "tap to refresh" bar appears instead.
17. **A browser cannot keep chosen files after a validation error**, so the person must pick them again (the form says so).
18. **HEIC photos are refused** (iPhone Safari normally converts to JPEG; some browsers do not). Allowed: jpg, jpeg, png, webp, pdf, docx, xlsx; 5 MB each; 5 files per upload. No virus scan.
19. **PHP upload limits** may be smaller than the app limits. Set `upload_max_filesize = 6M` and `post_max_size = 32M` in `php.ini`, or a phone photo fails with "too large" / HTTP 413.
20. **Search uses SQL `LIKE`**: `%` and `_` typed by the user act as wildcards.
21. **Reports:** the PDF lists the latest 300 rows (the CSV has all), the web list shows 50, the "Needs attention" list 25 (the real total is shown). *Tickets per day* covers 30 days. The attention list ignores the From / To dates on purpose.
22. **Help & FAQ:** an admin's change shows at once in the app; a change made directly in the database can take up to 10 minutes (or run `php artisan cache:clear`). Answers are plain text with bullets, steps, bold and links to this site only.
23. **Dashboard "Last update"** and many counters follow what the signed-in person may see, so two roles can legitimately see different numbers for the same day.

## D. E-mail, scheduler and queue

24. **E-mail is written but needs your provider.** `MAIL_MAILER=log` is the default (mail goes to `storage/logs/laravel.log`). Real delivery (Brevo SMTP) has to be set in `.env`, tested with `php artisan mail:test`, and the sender address verified at the provider, or messages are rejected. Provider daily limits apply.
25. **E-mails are queued** (`QUEUE_CONNECTION=database`). Without `php artisan queue:work` they wait in the `jobs` table (or use `QUEUE_CONNECTION=sync`, simpler but the page is slower). A failed e-mail never undoes the ticket action; see *Admin -> E-mail log*.
26. **Nothing automatic happens without the scheduler:** auto-close, auto-escalate, PM ticket generation (01:00) and PM reminders (07:00, Asia/Manila). Run `schedule:work` locally, or `schedule:run` every minute on the server.
27. **E-mail verification is off** (`REQUIRE_EMAIL_VERIFICATION=false`) until mail really works.

## E. PM Scheduler

28. **One ticket per schedule per due date**, and the generator never goes back in time: if the job missed days it makes one ticket and jumps `next_due_date` to the next future date. A test that needs an overdue PM ticket must set `requests.pm_due_date` by hand after generating.
29. **The maintenance calendar is plain server-rendered HTML** (month grid + list, GET filters). The JSON feed at `/maintenance-calendar/events` exists for a later interactive calendar but nothing uses it.
30. **PM access is narrow on purpose:** Maintenance Admin changes; the President (if present) only looks; Finance sees only the compliance report. Everyone else gets 403.
31. **On-time rate** = on time / (on time + late + overdue); not-due-yet and cancelled tickets are left out. The dashboard widget looks back 90 days (`PM_WIDGET_DAYS`).

## F. PWA

32. **No offline use of the app.** Offline shows only a small "You're offline" page, by design (no private data stored on phones). Web Push is **not** switched on (handlers exist in `sw.js`, but it needs VAPID keys and server code).
33. **Needs HTTPS or `localhost`.** `http://something.test` without SSL will not register the service worker.
34. **iPhone / iPad has no install button**: use Share -> Add to Home Screen.
35. **Bump `CACHE_VERSION`** in `public/sw.js` on every release, or phones may keep old CSS / JS.

See [PWA.md](PWA.md) for details.

## G. Technical debt worth knowing

36. **No security-header middleware** (CSP, X-Frame-Options) in the code; add them at the web server or in a middleware.
37. **Password rules are Laravel's default** (minimum length only). No two-factor sign-in, no password expiry.
38. **Breeze's "Delete account"** form is still on the profile page.
39. **SQL / queue tuning:** written for one school's traffic. Ticket-number retry and row transactions are fine for that size; nothing has been load-tested.
40. **Visual design** has not been re-checked in a browser for this documentation pass. Small layout differences can appear with long names or many items; use the phone-width cases in [TEST_PLAN.md](TEST_PLAN.md).
