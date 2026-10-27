# Update notes (Oct 16, 2026)

## Apply
```
composer install
copy .env.example .env      (then php artisan key:generate)
# Laragon: create an empty MySQL database named  school_maintenance
php artisan migrate:fresh --seed
php artisan storage:link
npm install
npm run dev
php artisan serve
```
Test logins (password: `password`): requester@, maintenance@, depthead@, finance@, treasurer@, custodian@, president@lcctanauan.edu.ph

## What's new
- Migrations 2026_10_16_000001..5: facilities + Form 1 tables, Form 2 tables, Form 3 table, `form_approvals` (signature lines), `notifications`
- Models: FacilityRequest, RequisitionSlip, RepairRequest (+ items, Facility, FormApproval) with the shared HasApprovalWorkflow trait
- President rule: any form refuses `closed` unless the President signed (Facility form also needs "returned")
- AdvanceNotice rule + 3 FormRequests (3 days / 3 working days / 5 working days) -- settings in config/school.php
- Notifications: FormNotifier service, FormActivity notification, NotificationController (poll/read), bell badge, pop-up + chime (resources/js/alerts.js)
- Maintenance card board (/maintenance/board) with ACCEPT TASK / MARK COMPLETED; repair form for requesters (/repairs/create)
- Optional instant delivery with Laravel Reverb (see .env.example)
- Timezone changed to Asia/Manila (needed for the advance-day rules)

## Not built yet
Blade pages + controllers for Form 1 and Form 2, approvals inbox for Dept Head/Finance/Treasurer/Custodian/President, PDF replicas of the 3 forms.

## Merge Phase M3: audit log
- `/admin/audit-logs` (route `admin.audit-logs.index`, capability `view_audit_logs`: maintenance_admin + president). Read-only; filters: user, action, date from/to (whole "to" day), search. 25 per page.
- Rows are written only through `App\Services\AuditLogger::log()` (never throws, strips passwords/tokens). `AuditLogger::EXPORT` is ready for the future export feature.
- Hooks: auth listeners + `UserObserver` (AppServiceProvider), `TicketWorkflow::apply()` / uploads, `AttachmentController::show`, `Admin\SettingsController::update`.
- Ticket rows are short and point at `request_status_logs` through `meta.status_log_id`; notes/reasons are not copied.
- Tests: `tests/Feature/AuditLogTest.php` (+ `AuditLoggerTest.php` for the foundation).

## Merge Phase M4: reports and CSV export
- `/reports` (route `reports.index`, capability `view_analytics`): tickets per status / category / month / department, overdue, emergency, average time to approve and to complete, workload per staff. Date filter on the submission date.
- `/reports/export` (route `reports.export`, capability `export_reports`): CSV of the same filtered list. UTF-8 BOM, text cells starting with `= + - @` are prefixed with `'` (`App\Support\Csv`). Every export is written to audit_logs (`export`).
- Everything goes through `App\Services\ReportMetrics::tickets()` = `visibleTo($user)` + dates, so confidential tickets only reach roles with `view_confidential`.
- Charts: Chart.js is an npm dependency bundled by Vite (`resources/js/reports.js`, loaded only on /reports). After pulling: `npm install` then `npm run build`.
- Tests: `tests/Feature/ReportTest.php`.
