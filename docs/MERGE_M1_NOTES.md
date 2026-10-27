# Merge Phase M1: settings, due date and overdue

Ported from the reference project (LCCT Alumni Ticketing V5.1: `admin/settings.php`, `includes/workflow.php`
`sla_hours()` / `ticket_is_overdue()`), rewritten for Laravel. Nothing was copied directly.

## What was added
- **`settings` table** + `App\Models\Setting` (`get`, `int`, `put`, cached; falls back to `config/school.php` -> `settings_defaults`).
- **`requests.due_at`** (nullable, indexed). Set when a ticket is created (`submitted_at` + SLA hours) and recomputed from
  `submitted_at` when the emergency flag is set or cleared. Existing tickets are back-filled by the migration.
- **`MaintenanceRequest::overdue()` scope, `isOverdue()`, `computeDueAt()`**. Overdue = past `due_at` AND status is pending
  (Submitted, Pending Authorization, Approved, Assigned, In Progress). Work Completed / Closed / Disapproved are never overdue.
- **`<x-due-badge>`** ("Due in 2d 4h" / "Overdue by 1d"), shown through `<x-ticket-badges>` on the ticket list, ticket page and approvals inbox.
- **"Overdue only" filter** on `/tickets` (`?overdue=1`).
- **`/admin/settings`** (capability `manage_settings`, Maintenance Admin only): SLA hours (routine / emergency), reopen window
  (used by M2), and an optional "re-date pending tickets" checkbox. A "Settings" link appears in the menu for allowed roles.

## Files
Added: `app/Models/Setting.php`, `app/Http/Controllers/Admin/SettingsController.php`, `resources/views/admin/settings.blade.php`,
`resources/views/components/due-badge.blade.php`, `tests/Feature/SettingsAndDueDateTest.php`,
migrations `2026_10_27_000001_create_settings_table.php` and `2026_10_27_000002_add_due_at_to_requests_table.php`.
Changed: `app/Models/MaintenanceRequest.php`, `app/Http/Controllers/TicketController.php`, `config/school.php`, `config/permissions.php`,
`routes/web.php`, `resources/views/layouts/navigation.blade.php`, `resources/views/tickets/index.blade.php`,
`resources/views/components/ticket-badges.blade.php`, `.gitignore`.

## How to apply
```
php artisan migrate          # adds settings + requests.due_at (does NOT touch other tables)
php artisan test
```
Do NOT use `migrate:fresh` on a database that holds real data.

## Manual test
1. Log in as `maintenance@lcctanauan.edu.ph` -> menu **Settings** -> change routine hours to 1 -> tick "re-date pending" -> Save.
2. Open **Tickets**: pending tickets older than 1 hour show a red "Overdue by ..." badge; tick **Overdue only** and Apply.
3. Log in as `requester@...`: no Settings link; opening `/admin/settings` gives 403.
4. File a new ticket, then flag it as emergency: its "Due in" time shrinks to the emergency hours.

## Not verified yet
This was written without PHP available, so **`php artisan migrate` and `php artisan test` have not been run**.
Run them first and send me any error text.

## Decisions to confirm with the client (also in docs/OPEN_QUESTIONS.md)
Hours are calendar hours; defaults 120 h routine (= the 5 working days on FO-08) and 24 h emergency; `needed_by` (the date
the requester asked for) does not change `due_at`.
