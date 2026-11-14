# Phase 6 — Department dashboards and queues

## PREAMBLE

The submitted specification begins with `[Ilagay ang PREAMBLE]`; the official wording was not supplied.
This document records the Phase 6 behaviour without inventing or replacing the requested preamble.

## Dashboard roles

All dashboard queries enforce `MaintenanceRequest::visibleTo($user)` first, then narrow to the
receiving department (`assigned_department_id`) for scoped queues. The visibility scope remains the
security boundary: requester/assignee exceptions and confidential-ticket rules are unchanged.

| Role | Dashboard |
|---|---|
| Department Staff (`staff`) | Their own pending assigned tickets; the department's unassigned tickets in assignable statuses; overdue department tickets. The visible list is their own assigned queue. |
| Department Head (`head`) | All tickets for the receiving department, open count, own assigned queue, unassigned queue, overdue count, escalated count/list, and active Staff workload (open assigned ticket count per person, including zero). |
| System Administrator (`system_admin`) | Per-department total, open and overdue ticket counts, plus school-wide open / overdue / escalated totals. Counts are grouped by `assigned_department_id`. |
| Maintenance Admin | Existing Maintenance dashboard is retained. Its queries still start with `visibleTo()`, so the existing department scope remains enforced. |

"Open" / pending means `TicketStatus::pendingValues()`; overdue uses the existing `overdue()` scope,
which excludes Waiting for User while the SLA clock is paused. No priority levels were introduced.

## Ticket list filters and search

The active ticket list already had status, priority (routine / emergency), assignee, submission date
range, and search controls. Phase 6 makes the department filter mean the **receiving / assigned
department** (`assigned_department_id`) rather than the requester's office (`department_id`), makes
search also match a numeric internal ticket ID exactly, and fixes wildcard injection/over-broad search.
The same `applyFilters()` is used by the list and its CSV export; `visibleTo()` is applied before
filters in both paths. Ticket History keeps its existing supported filters (status, date, query,
"mine").

Search escapes literal `!`, `%`, and `_`, then uses `LIKE ... ESCAPE '!'` for ticket number,
description, location, reason and requester name. These characters no longer become SQL wildcards
(LIMITATIONS #20 resolved).

## Live refresh

`/live` uses `LiveSignature::forList()` / `forTicket()`, both based on `visibleTo($user)`. Therefore
changes in another department do not change a department-scoped user's list signature, while a
visible in-department ticket change does. Internal-note conversation visibility remains role-aware.

## Tests

- `tests/Feature/DepartmentDashboardAndQueueTest.php` — Department Staff / Head isolation, counts,
  escalations, workload, System Admin department totals, combined filters, wildcard search and numeric IDs.
- `tests/Feature/MaintenanceDashboardTest.php` — existing Maintenance Admin dashboard and new Staff / Head role dashboards.
- `tests/Feature/LiveRefreshTest.php` — department Staff signature ignores foreign department changes and updates for local ticket changes.

## Out of scope

No new priority levels, reports, or SLA changes.
