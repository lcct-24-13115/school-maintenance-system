# Open questions for the client

Every item below is implemented with a **default** and is changeable in config (not hard-coded).
Each one is marked `// CONFIRM WITH CLIENT` in the code. Add new rows as later phases need decisions.

| # | Phase | Question | Current default | Where to change it |
|---|-------|----------|-----------------|--------------------|
| 1 | 2 | Which user groups exist and what can each do? The proposal only names "Admin" and "Finance". | Admin = `maintenance_admin` (everything except authorize/disapprove); Finance = view all non-confidential tickets, analytics, exports; President = view all incl. confidential, authorize, disapprove, flag emergency, analytics, exports; Requester, Department Head, Treasurer, Custodian = no global powers | `config/permissions.php` |
| 2 | 2 | Who may see **confidential** tickets? | Requester, assignee, and roles with `view_confidential` (Maintenance Admin, President). Finance may not. Department Heads may not (even for their own department). | `config/permissions.php` (`view_confidential`) |
| 3 | 2 | Who may flag / clear an **emergency**? | Flag: Maintenance Admin, President. Clear: Maintenance Admin. A requester who ticks "Emergency" files a routine ticket plus a history note "Requester asked for emergency review: ...". | `config/permissions.php` (`flag_emergency`, `clear_emergency`) |
| 4 | 2 | Should the emergency exemption from the "5 working days in advance" rule apply only to people who may flag? | Yes. Others still need a valid date. | `StoreRepairRequestRequest`, `school.emergency_bypasses_advance_notice` |
| 5 | 2 | Do Department Heads see their department's tickets? Any other role with department-level visibility? | Department Head only, non-confidential tickets only | `config/school.php` (`department_visibility_roles`) |
| 6 | 2 | Which roles may file repair requests? | Every role (same as Phase 1) | `config/school.php` (`filing_roles`) |
| 7 | 2 | Should self-registration at `/register` stay open in production? | Open (creates a Requester) | `.env` `ALLOW_PUBLIC_REGISTRATION=false` / `config/school.php` |
| 8 | 4B | The exact "signed evidence" rule for closing a ticket. | Declared as `closure_requires = ['authorization', 'signed_evidence']`; enforced in Phase 4B (until then the Phase 1 rule applies: authorization recorded). | `config/school.php` -> `workflow.closure_requires` |
| 9 | later | Retention period for tickets and attachments. | Not set yet | Phase 9 |
| 10 | 3 | Who may add files after a ticket is filed, and who may upload `signed_evidence` / `work_photo` files? | Add: Maintenance Admin, the assignee, and the requester of their own ticket until it is Closed/Disapproved. Kinds other than `request`: only users with the `work` capability (Maintenance Admin). The President cannot upload yet. | `TicketPolicy@addAttachment`, `StoreTicketAttachmentRequest::allowedKinds()`, `config/permissions.php` |
| 11 | 3 | Allowed file types and sizes? | jpg, jpeg, png, webp, pdf, docx, xlsx; 5 MB each; 5 files per upload | `config/school.php` -> `attachments` |
| 12 | 3 | Which file names count as a "double extension"? | A middle part that is a script/program extension (php, exe, js, html, svg...) or another allowed extension (`file.png.jpg`). Names with ordinary dots are accepted. | `config/school.php` -> `attachments.blocked_inner_extensions` |
| 13 | 3 | Should confidential tickets also keep their files from Finance / Department Heads? | Yes - a file is visible exactly when its ticket is visible | `MaintenanceRequest::isVisibleTo()` |
| 14 | 4A | Who are the **authorizers** (final signatories)? Can anyone besides the President approve / disapprove? | President only | `config/school.php` -> `workflow.authorizers` |
| 15 | 4A | May the authorizer approve **directly from Submitted**, or must Maintenance first send it for authorization? | Directly allowed (and Maintenance can still send it to Pending Authorization) | `config/school.php` -> `workflow.authorize_from_submitted` |
| 16 | 4B | Who may **close** a ticket? | Maintenance Admin and President | `config/school.php` -> `workflow.closers` (enforced in Phase 4B) |
| 17 | 4A | Who may **send a ticket for authorization**? | Maintenance Admin | `config/permissions.php` (`send_for_authorization`) |
| 18 | 4A | Who may **add notes** to a ticket? | Requester (own), assignee, authorizers, Maintenance Admin. Finance, Dept. Heads, Treasurer, Custodian are view-only until their roles are confirmed. | `TicketPolicy@addNote` |
| 19 | 4A | Is the note when **clearing an emergency** required? Who may clear? | Optional note; Maintenance Admin only (the President may flag but not clear) | `config/permissions.php` (`clear_emergency`), `TicketActionController@clearEmergency` |
| 20 | 4A | Can the authorizer upload a signed file when approving? | Yes, stored as `signed_evidence` (the President still cannot upload files from the attachments box) | `TicketActionController@approve` |
