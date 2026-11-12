# Multi-department conversion: notes

## Phase 1: departments can receive tickets

**What changed**
- `departments`: new columns `is_active`, `is_service_department`, `head_user_id`, `description`.
- `requests` (tickets): new column `assigned_department_id` = the department that RECEIVES the ticket.
  The existing `department_id` is still the REQUESTER's department.
- `DepartmentSeeder`: 12 departments. Maintenance Office (`MNT`) is the new one; it is last on purpose because
  `UserSeeder` picks the "first" department by id.
- Existing tickets are back-filled to the Maintenance Office by the migration
  (it creates the `MNT` row itself if there are tickets and the row is missing).
- Until routing exists (Phase 3), a new ticket without `assigned_department_id` is given the Maintenance Office
  (`MaintenanceRequest::booted`, `Department::maintenanceId()`).

**Decisions** (add to `OPEN_QUESTIONS.md` when the school answers)
- All 12 departments are service departments by default. CONFIRM WITH CLIENT.
- `head_user_id` is only a pointer for now. Assigning heads is done in Phase 5 (department management).

**Not done yet** (later phases): `categories.department_id` (Phase 2), routing (Phase 3), department-scoped roles (Phase 4).
