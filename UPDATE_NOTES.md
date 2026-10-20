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
