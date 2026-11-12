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

**Not done yet** (later phases): routing (Phase 3), department-scoped roles (Phase 4).

## Phase 2: categories per department

**What changed**
- `categories.department_id` (FK). Existing categories are back-filled to the Maintenance Office.
- The unique rule is now `department_id + name + type` (before: `name + type`), so two departments may both have e.g. "Other".
- New type `service` (`Category::TYPE_SERVICE`) for every department except the Maintenance Office, which keeps
  Materials / Equipment / Supplies because the repair form depends on them.
- `CategorySeeder` adds the 5 sample categories of each of the 11 other departments (checklist section 3) plus a locked
  "Other" per department. Maintenance keeps its existing categories; its checklist samples (plumbing, electrical,
  air-conditioning, furniture, facilities) are already covered by them.
- `Category::otherFor($type, $departmentId)`: the catch-all is per department; deleting a category moves its tickets
  to the "Other" of the SAME department and type.
- A new category without a department goes to the Maintenance Office (`Category::booted`), like before Phase 2.
- Admin > Categories has a department picker; the add form sends the chosen department.
- The repair form (`/repairs/create`) and its validation only accept Maintenance categories. Other departments get
  their own ticket form in Phase 3.

**Known side effects / decisions**
- The ticket list filter (`TicketController`) still lists ALL categories in one dropdown. Phase 6 (filters per department) will fix that.
- No subcategories were seeded for the new departments; the admin can add them (Admin > Categories > Subcategories).
- Rolling back the migration fails if two departments already share a category name + type. Rename or delete those first.
