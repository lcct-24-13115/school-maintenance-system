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

## Phase 3: ticket creation + routing

**What changed**
- `/tickets/create` (`tickets.create`) and `POST /tickets` (`tickets.store`) are now the **generic ticket form**
  (`TicketController@create` / `@store`, `StoreTicketRequest`, `resources/views/tickets/create.blade.php`).
  Before, `/tickets/create` was only an alias of the repair form. `/repairs/create` (FO-08) is unchanged and still
  files to the Maintenance Office.
- Fields: title, description, receiving department (`assigned_department_id`), category (the list follows the chosen
  department), optional subcategory, priority, contact name / email / phone, attachments (`ValidAttachment` +
  `AttachmentService` through `TicketWorkflow::open`). An account with no department (admin, president...) also picks the
  office it files **from** (`from_department_id`), because `requests.department_id` cannot be empty.
- **`App\Services\TicketRouter`** (static, no HTTP):
  - `route($category, $departmentId, $subcategory, $assignee)` -> `RoutingDecision`. The receiving department is taken
    from the CATEGORY (`categories.department_id`). A department that disagrees with the category is refused
    (`TicketRoutingException`, which names the form field). It also refuses a hidden category, a department that is switched off or
    not a service department, a subcategory of another category, and an assignee who is not active staff of that department.
  - **Default queue**: with no assignee the ticket keeps `assigned_to = NULL`. `MaintenanceRequest::scopeUnassignedQueue($departmentId)`
    and `TicketRouter::unassignedQueue($department)` list it (open tickets only). A named assignee, or the optional
    `school.routing.auto_assign_head`, skips the queue.
  - `receivers($ticketOrDepartment)`: who is alerted.
- **Alerts** (`TicketMailer`, `FormNotifier`) now go to `TicketRouter::receivers($ticket)` instead of every Maintenance Admin:
  active Staff / Head / Maintenance Admin accounts whose `department_id` is the receiving department, plus the department's
  `head_user_id`. The Maintenance Office (and a ticket without a department) keeps the old audience, so the FO-08 flow is unchanged.
  The President / authorizer alerts are not touched.
- Migration `2026_11_27_000001`: `requests.title`, `contact_name`, `contact_email`, `contact_phone`, `location` is now nullable,
  and an index for the queue. `MaintenanceRequest::headline()` = title, else location, else the start of the description (used in
  the lists, the ticket page and the alerts).
- The first history line of a routed ticket is a system note ("Routed to Registrar. Waiting in the unassigned queue.").

**Decisions (add to `OPEN_QUESTIONS.md` when the school answers)**
- The generic form does NOT accept the Maintenance Office (`school.routing.allow_maintenance_on_generic_form`, default false):
  repairs / materials / equipment / supplies keep their 5-working-days rule, items and typed categories on FO-08.
- A department with no staff / head account yet alerts the maintenance team (`school.routing.fallback_to_maintenance`, default true).
- "Staff / head of a department" = existing roles (`staff`, `head`, `maintenance_admin`) with that `department_id`. No new roles.

**Known gaps (later phases, on purpose)**
- Visibility is unchanged: staff / head / admin still see every non-confidential ticket (`view_all_tickets`), and the maintenance
  board and dashboards still list tickets of every department. Scoping them to the receiving department is a later phase.
  A `head_user_id` who has the `department_head` role is not alerted about tickets received (that role only sees tickets FILED by its department).
- `ticket_no` keeps the `MT-` prefix for every department.
- Claiming / assigning a ticket of another department still relies on the old rule (`TicketWorkflow::assign` accepts any maintenance staff).
