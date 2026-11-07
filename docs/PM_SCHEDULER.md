# PM Scheduler: how the pieces fit

Preventive maintenance (PM) repeats on its own. This page follows ONE schedule from creation to the report.

```
PM schedule (admin/pm-schedules)
   |  every day 1:00 AM, a few days before the due date (lead_time_days)   <- PmTicketGenerator::runDue()
   v
PM ticket (one per schedule per due date)  ------------------------>  Maintenance calendar (/maintenance-calendar)
   |  Accept the task  (Assigned -> In Progress)                        shows the ticket in its real colour
   |  Complete PM form (date, technician, checklist, notes)             <- PmCompletion::complete()
   v
Work Completed  --> schedule.last_completed_date, next_due_date
   |   fixed    keep the original rhythm (the generator already moved next_due_date)
   |   floating count the next date from the day the work was really done (anchor_date)
   v
PM compliance report (/pm-reports, CSV, PDF)  +  dashboard cards      <- PmCompliance
```

## Pages and who sees them

| Page | URL | Maintenance Admin | President | Finance | Others |
|---|---|---|---|---|---|
| PM schedules, Equipment | `/admin/pm-schedules`, `/admin/equipment` | change | look | no | 403 |
| Maintenance calendar | `/maintenance-calendar` | yes | yes | no | 403 |
| PM compliance report | `/pm-reports` (+ `/export`, `/pdf`) | yes | yes | yes | 403 |

Capabilities live in `config/permissions.php` (`manage_pm_schedules`, `view_pm_schedules`, `view_pm_reports`).

## The calendar (E4)

`MaintenanceCalendar::events()` builds the dates and does not save anything. `MaintenanceCalendarController::index()` lays one month out as a
Sunday-to-Saturday grid. The same events are also at `/maintenance-calendar/events` as JSON.

| Colour | Meaning |
|---|---|
| Blue | coming up: no ticket yet |
| Yellow | ticket open / in progress |
| Green | Work Completed / Closed |
| Red | ticket still open and past its PM due date |
| Grey | Cancelled / Disapproved |

## Rules that are easy to forget

* One ticket per (schedule, due date): the database enforces it, so running the job twice never doubles a ticket.
* If the job missed days, ONE ticket is made for the stored due date and `next_due_date` jumps to the next FUTURE date.
* `PmTicketGenerator::generate()` never goes back in time. A test that needs an overdue ticket must set `requests.pm_due_date` by hand after generating it.
* On time = done on or before the PM due date. Rate = on time / (on time + late + overdue). Not-due-yet and cancelled are not counted.
* Open decisions for the client are in `docs/OPEN_QUESTIONS.md` (#67-80).

## Tests

| File | What it covers |
|---|---|
| `PmScheduleTest`, `PmScheduleBulkTest` | schedules: create, edit, pause, duplicate, delete |
| `PmTicketGeneratorTest` | lead time, catch-up, one ticket per date |
| `PmCompletionTest`, `PmChecklistTest` | the Complete PM form, fixed / floating |
| `MaintenanceCalendarTest` | the JSON feed |
| `PmCalendarPageTest` (E4) | the calendar page: access, month, filters, links |
| `PmReportTest` | the compliance report and dashboard cards |
| `PmEndToEndTest` (E4) | schedule -> ticket -> completion -> next due date -> calendar -> report |

Run only the PM tests: `php artisan test --filter=Pm`
