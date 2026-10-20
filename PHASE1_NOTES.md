# Phase 1 - Unified ticket foundation (Oct 19, 2026)

## Apply
```
composer install                      (nothing new to install)
php artisan migrate:fresh --seed      (new migration 2026_10_19_000001 + 40 sample tickets)
npm run dev
php artisan serve
php artisan test --filter=TicketFoundationTest
```
Logins (password `password`): requester@, requester2@, requester3@, maintenance@, maintenance2@, maintenance3@,
finance@, president@, depthead@, treasurer@, custodian@ `school.test`

## What changed
- **One ticket table.** `requests` / `MaintenanceRequest` is THE ticket. `reference_no` -> `ticket_no`, format `MT-YYYY-00001`
  (numbers restart every year; unique index + retry on a clash).
- **8 statuses** in `App\Enums\TicketStatus` with the only allowed moves (Closed and Disapproved are final).
  The model refuses a skipped step even for a direct `->update(['status' => ...])`.
- **`App\Services\TicketWorkflow`** is the one place that changes a ticket: `open`, `transition`, `authorize`, `disapprove`,
  `assign` (reassign needs a note), `startWork`, `markWorkCompleted`, `close`, `flagEmergency` (reason required), `clearEmergency`, `addNote`.
  Each writes one line to `request_status_logs` (new columns `action`, `meta`) in the same transaction.
- **New ticket columns:** is_emergency, emergency_reason/flagged_by/flagged_at, is_confidential, needed_by, reason,
  assigned_at, problems_encountered, recommendations. `priority` is kept but always mirrors `is_emergency`.
- `request_type` is now optional and has a third value, `supplies` (Office/Cleaning Supplies moved there).
- **FO-08 repair form** (`/repairs/create`) now files a ticket in `requests`. It has a new required *Maintenance category* field.
  The old `repair_requests` table is left in place but no longer used for new records.
- **Maintenance board** reads tickets: ACCEPT TASK works on Approved tickets (assigns to you + In Progress),
  MARK COMPLETED works on In Progress. Tickets still waiting for authorization show "Waiting for authorization".
- Dashboards use the new statuses. Notifications work for tickets (`form_type = ticket`).
- `TicketSeeder`: 40 tickets over 12 months, every status, 6 emergency, 3 confidential, full history each.

## Heads-up while testing before Phase 3
There is no approval screen yet, so a ticket you file will sit at "Submitted" and the board will not let staff accept it.
To approve one by hand:
```
php artisan tinker
>>> $t = App\Models\MaintenanceRequest::latest('id')->first();
>>> App\Services\TicketWorkflow::authorize($t, App\Models\User::where('email','president@lcctanauan.edu.ph')->first());
```
Seeded data already has an Approved, an Assigned and an In Progress ticket for the board.

## Decisions to confirm later
- Anyone who picks "Emergency" on the repair form gets the flag, with their *reason* text as the emergency reason.
  Phase 2 decides who is actually allowed to flag.
- Closing needs recorded President authorization (kept from the old rule). Phase 3 replaces this with the final
  "signed evidence" rule once the client confirms the signatories.
- New uploads still go to the `public` disk (the board shows the photo). Phase 4/9 moves them to a private disk
  behind an authorized route.
