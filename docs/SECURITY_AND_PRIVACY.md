# Security and data-privacy notes

What the code already does, what you must set before going live, and what is still open.
Every row names the file where the control lives. Nothing here is a certification: see the Data Privacy Act section at the end.

## 1. Controls implemented in code

| Risk | Control | Where |
|---|---|---|
| SQL injection | Eloquent / query builder, values are bound parameters. | models, controllers |
| XSS | Blade escapes every `{{ }}`. File names are sanitised (path, control characters, `<>:"/\|?*`, 100 chars) and escaped. FAQ answers are plain text with a small safe format; anything else is escaped. The `.docx` preview is shown with `textContent` only. | `FaqText`, `DocxPreview`, `AttachmentService` |
| CSRF | Laravel's CSRF token on every state-changing request (all are POST / PUT / PATCH / DELETE). Cookie is `SameSite=Lax`. | `web` middleware group |
| Broken access control / IDOR | Capability matrix + policies + per-record visibility. `{ticket}` and `{attachment}` are resolved through `visibleTo()`, so a hidden record is **404**. Admin controllers check the capability **before** looking the id up. Role and active state are read from the database on every request. | `config/permissions.php`, `TicketPolicy`, `CheckRole`, `AppServiceProvider` |
| Confidential tickets | Visible only to the requester, the assignee and roles with `view_confidential`. The same rule covers files, reports, the live-refresh fingerprint (a hash) and e-mails (number, status and link only). | `MaintenanceRequest::visibleTo()`, `LiveSignature`, `TicketMailer` |
| Weak passwords | bcrypt (`BCRYPT_ROUNDS=12`). Self-registration and the forced change use `Password::defaults()` (Laravel default: 8+ characters, **no extra complexity rules** configured); a forced change must differ from the current password. | `RegisteredUserController`, `ForcePasswordChangeController` |
| Temporary passwords | Accounts made by an admin, the student import or `students:reset-passwords` get `must_change_password = true`; until changed the user can reach only the change-password page and logout (background requests get 423). | `EnsurePasswordChanged` |
| Brute force | After **5** wrong passwords (same e-mail + same IP) sign-in is locked for **15** minutes; even the right password is refused while locked. The message is neutral. Each lock is written to the audit log (`account_locked`). Pending / rejected accounts see their status message only after typing the **right** password, so the page cannot be used to discover who is registered. | `LoginRequest`, `school.login` |
| Session | Database sessions, 120-minute lifetime, `HttpOnly`, `SameSite=Lax`. Set `SESSION_SECURE_COOKIE=true` on HTTPS. | `config/session.php`, `.env` |
| Password reset / verification | Laravel's broker: single-use hashed tokens, reset link valid `PASSWORD_RESET_MINUTES` (60), verification link `VERIFY_EMAIL_MINUTES` (1440), signed. A bad or old verification link shows a friendly page. | `config/auth.php`, `bootstrap/app.php` |
| Unsafe uploads | Type detected on the **server** from the file contents (finfo), never the browser's mime; extension must be jpg, jpeg, png, webp, pdf, docx or xlsx **and** match the detected type; double extensions (`file.php.jpg`) refused; docx / xlsx zips must contain the right inner file; 5 MB each, 5 files per upload; random stored names. | `AttachmentService`, `ValidAttachment`, `school.attachments` |
| File delivery | Files sit on the private `local` disk (`storage/app/private`); the built-in `/storage` route for it is off (`serve = false`). They open only through `GET /attachments/{id}` with `X-Content-Type-Options: nosniff` and `Cache-Control: private`. Images and PDF open inline; everything else downloads. An internal-note file is also refused to the requester. | `AttachmentController`, `config/filesystems.php` |
| Profile photos | JPG / PNG / WEBP, 3 MB, checked by real image type, stored on the private disk and served only by `avatars.show` to signed-in users. | `ProfileController` |
| CSV / Excel formula injection | A text cell starting with `= + - @` (or tab / CR) gets a leading `'`. | `App\Support\Csv` |
| Abuse of cheap endpoints | Rate limits: replies 30/min, live refresh 120/min, calendar JSON 60/min, e-mail retry 20/min, public Help 60/min, FAQ votes 30/min. | `routes/web.php` |
| Duplicate / racing writes | Ticket number has a unique index with retry on a clash; ticket + history + files are saved in **one transaction** and saved files are deleted again if anything fails. | `TicketWorkflow::open()` |
| Audit trail | Read-only `/admin/audit-logs` (Maintenance Admin and President). Logged: sign-in / sign-out / failed sign-in / lock, registration, password resets, user create / update / (de)activate / role change, account approve / reject, settings, categories, QR codes, PM schedules, equipment, FAQ, e-mail retry, ticket status / assign / reassign, file upload / download / delete, **every export**. Passwords, tokens, cookies and `Authorization` values are never stored (keys dropped, text redacted). | `AuditLogger` |
| E-mail log | Every e-mail the system sent with its real result. Only `view_email_log` (Maintenance Admin). "Send again" for failed ticket e-mails is audited. | `EmailLogController` |
| Visitor privacy (Help & FAQ) | A visitor who votes is stored only as a hash of a random session token plus a **keyed hash** of the IP. "No answer" searches keep the words only, never who searched, and drop terms with `@`, `://`, `www.` or 6+ digits. | `FaqFeedback`, `FaqSearchMiss` |
| Public sign-up | `/register` is a 404 unless `ALLOW_PUBLIC_REGISTRATION=true`; when open, e-mail domain / format rules apply (`SCHOOL_EMAIL_DOMAIN`, `SCHOOL_EMAIL_FORMAT`) and, by default, an admin must approve the account. | `school.registration` |
| PWA | The service worker never stores a signed-in page, a file, an avatar or a QR image on the device. See [PWA.md](PWA.md). | `public/sw.js` |

## 2. Things you must do before real use

1. **Change the seeded passwords.** `UserSeeder` creates `maintenance@`, `custodian@` and `finance@lcctanauan.edu.ph` with the password `password`, and does not force a change. Reset them from `/admin/users` or deactivate the accounts you do not use.
2. **`.env` for production:** `APP_ENV=production`, `APP_DEBUG=false` (the example file ships with `true`), a real `APP_KEY`, `APP_URL` set to the real HTTPS address (e-mail and QR links use it), `SESSION_SECURE_COOKIE=true`.
3. **Use a dedicated database user** (not `root`, no `DROP` / `ALTER`) and a strong password. Never commit `.env`.
4. **Serve over HTTPS** with `public/` as the web root. Nothing else (`app/`, `storage/`, `.env`) may be reachable from the browser. Check that `/.env`, `/storage/app/private/...` and `/vendor/...` answer 404 / 403.
5. **E-mail secrets:** the Brevo SMTP key lives only in the real `.env`. Set `MAIL_FROM_ADDRESS` to a sender verified at the provider.
6. **Backups:** schedule database **and** `storage/app/private` backups and test a restore at least once. Uploaded files are not in the database.
7. **Scheduler and queue:** run `schedule:run` every minute and a `queue:work` (or `QUEUE_CONNECTION=sync`), otherwise auto-escalation, auto-close, PM tickets and e-mails do not happen.
8. **Students' first password is their student number.** Anyone who knows a student number can sign in until the student changes it. Import close to the day students are told to sign in, and watch the audit log for first sign-ins.
9. **Keep PHP, Composer packages and npm packages patched** (`composer audit`, `npm audit`).

## 3. Known gaps (see also [LIMITATIONS.md](LIMITATIONS.md))

* No `Content-Security-Policy`, `X-Frame-Options` or other security-header middleware is configured in the code. Add them at the web server or in a middleware.
* No two-factor sign-in, no password expiry, no password-strength rules beyond Laravel's default minimum length.
* No virus scanning of uploads.
* A signed-in user can delete **their own account** (Breeze's *Delete account* form on the profile page, password-confirmed). Decide whether to keep it; tickets keep their history rules, but the user row is removed.
* Votes and "no answer" search terms in the FAQ are kept forever (no retention period yet).
* No retention or disposal rule exists yet for tickets, attachments, audit logs or e-mail logs (OPEN_QUESTIONS #9).
* Search uses SQL `LIKE`: a `%` or `_` typed by the user acts as a wildcard.

## 4. Data Privacy Act (RA 10173): practical notes, not a legal assessment

* The system holds personal data: names, school e-mail, student / employee number, department, optional profile photo, and the text and photos people attach to tickets (which can show faces or locations). Collect and keep only what the office needs.
* Role-based access limits who sees requester data; internal notes never reach the requester; confidential tickets, their files and their e-mails are restricted; the audit log records exports and file downloads.
* The school still has to decide and write down: a **privacy notice** shown to users, a **retention period** and disposal procedure, who the **Data Protection Officer** contact is, and a **breach-response** procedure.
* This software does **not** make the school compliant by itself and no certification is claimed. Have the school's DPO review the notice, retention policy and breach procedure.
