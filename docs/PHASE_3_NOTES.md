# Phase 3 - Complete request registration + private, policy-protected files (Oct 20, 2026)

> **Not tested.** Like Phase 2, this was written in an environment with no PHP, no Composer and no network, so
> `php artisan test`, `migrate`, `vendor/bin/pint` and a browser check were **not run**. I only checked brace/bracket
> balance of every PHP file I touched. Run the commands under "Apply" first and send me any failure (see "Known gaps").

## Apply
```
php artisan migrate                      # NEW migration: 2026_10_20_000001_add_storage_columns_to_request_attachments
php artisan attachments:move-to-private  # only if you already have uploaded files (add --dry-run first to preview)
npm run dev
php artisan serve
php artisan test
vendor/bin/pint app config database routes tests
```
No new Composer or NPM packages. Fresh database instead? `php artisan migrate:fresh --seed` works too.

**PHP upload limits (important on a phone).** Phone photos are often 3-8 MB and the form can send 5 files (up to 25 MB).
In `php.ini` set `upload_max_filesize = 6M` and `post_max_size = 32M` (PHP's default `upload_max_filesize` is only 2M), then
restart `php artisan serve`. Too small a limit shows "... is too large" for a single file, or HTTP 413 when the whole
request is bigger than `post_max_size`.

## What changed
- **Migration (additive)** on `request_attachments`: `disk` (default `local`; rows that already exist are backfilled to
  `public`), `kind` (default `request`; `request | signed_evidence | work_photo`), `size_bytes` (nullable), index on
  `(request_id, kind)`.
- **`php artisan attachments:move-to-private`** (`--dry-run`, `--keep-source`): copies each row still on `public` to the private
  `local` disk at `ticket-attachments/{year}/{month}/{same random name}` (year/month = the row's `created_at`), checks the copy
  size, then updates `path`, `disk`, `size_bytes` and **replaces the stored mime type with the one detected from the file**
  (the old value came from the browser), then deletes the public file. Safe to re-run; a missing file is reported and skipped.
- **Private storage**: `RequestAttachment::DISK = 'local'` (storage/app/private). New files are saved as
  `ticket-attachments/{year}/{month}/{40 random chars}.{ext}`. `config/filesystems.php`: the `local` disk now has
  `'serve' => false`, so Laravel's built-in `/storage/...` route for it is gone.
- **`GET /attachments/{attachment}`** (`attachments.show`, `?download=1` forces download): `{attachment}` is resolved through the
  visibility of its ticket (`AppServiceProvider`, same idea as `{ticket}`) so a hidden file is a **404**, then
  `TicketPolicy@view` is checked on the parent ticket, so the confidential rules apply. Headers: `X-Content-Type-Options: nosniff`,
  `Cache-Control: private`. Images and PDFs display inline; everything else (and anything whose stored type is not on the
  whitelist) downloads. `RequestAttachment::url()` / `downloadUrl()` and the board photo use this route.
- **One validator for every file input** - `App\Services\AttachmentService` (rules + saving) and `App\Rules\ValidAttachment`
  (the form rule). Settings: `config/school.php` -> `attachments` (types, `max_kb` 5120, `max_files` 5, blocked inner extensions).
  - type = **server-side detected** (finfo) from the file contents, never the browser's mime;
  - extension must be one of jpg, jpeg, png, webp, pdf, docx, xlsx **and** match the detected type (a PNG named `.jpg` is refused);
  - double extensions refused (`file.php.jpg`, `photo.png.jpg`: a middle part that is a script/program extension or another allowed
    extension). Ordinary dots (`My photo 2026.10.20.jpg`) are fine;
  - docx/xlsx are zip files, so finfo may say "zip" - the zip must also contain `[Content_Types].xml` and `word/document.xml` (or
    `xl/workbook.xml`);
  - 5 MB per file, 5 files per upload, empty files refused, failed PHP uploads give a clear message (the rule is "implicit" so a
    file PHP rejected is not silently skipped);
  - displayed name is sanitized (path, control characters, `<>:"/\|?*`, 100 characters) and always escaped in Blade.
- **Request form** (`/repairs/create`, plus alias `/tickets/create` named `tickets.create`):
  - `files[]` replaces `photo`: "Take photo" (camera on phones) and "Choose files" (several at once) share one list; names and sizes
    are shown with "Remove" before sending; oversize / too many are flagged in the browser (the server checks again);
  - "Request type" (None / Materials / Equipment / Supplies) filters the Category list to that type. With a type, the category must be
    of that type (form error "Please choose a category that matches the request type"); the model rule is still the second lock;
  - items repeater (name, quantity, unit, specification) appears only when a type is chosen; saved to `request_items`. If a type is not
    chosen, any items the browser still sent are dropped; completely empty rows are dropped;
  - "Mark as confidential" checkbox with helper text; still required: category, location, description, reason, needed_by
    (5-working-day rule and the emergency bypass for people who can flag are unchanged).
- **`TicketWorkflow::open($requester, $data, $files = [], $items = [])`** saves ticket, history, items and files in **one
  transaction**; files are checked before anything is written, and if anything fails the files already saved are deleted again.
  The third argument still accepts a single `UploadedFile` or `null`, so old callers keep working.
- **`TicketWorkflow::addAttachment($ticket, $file, $by, $kind)`** and **`addAttachments($ticket, $files, $by, $kind)`**: same file
  rules, `kind` must be one of the three, one history line per upload ("Added 2 files (Request file): a.pdf, b.jpg",
  action `attachment`). `signed_evidence` is only *uploadable*; no closure rule uses it yet (Phase 4).
- **Ticket detail**: attachment list with icon, name (opens inline for images/PDF), size, uploader, time, kind badge, Download.
  `POST /tickets/{ticket}/attachments` (`tickets.attachments.store`) adds files after filing.
- **`TicketPolicy@addAttachment`** now means: Maintenance Admin (`add_attachment`), the assignee, or the **requester of their own
  ticket while it is not Closed/Disapproved** - and always only if they can see the ticket. (Before it only checked the capability.)
  Only people with the `work` capability may choose the kind `signed_evidence` / `work_photo`; everyone else adds `request` files.

### Changes you did not list (please check)
- `config/filesystems.php`: `local.serve` = `false`.
- `RequestStatusLog::ACTION_ATTACHMENT = 'attachment'` (history label "Attachment").
- `MaintenanceRequest::photoUrl()` (board card) uses the first **request** photo; later work photos do not replace it.
- If `TicketWorkflow::open()` still throws a rule error that the form could not foresee, the form shows it at the top instead of a 500.
- Added `[x-cloak]` CSS to `resources/css/app.css`.

### Test changes
- No existing test was changed. New: `tests/Feature/AttachmentTest.php` (valid files accepted; wrong type / fake and double extension /
  oversize / too many rejected with their messages; files land on the private disk and `/storage/...` gives 404; confidential
  attachment: requester + admin 200, unrelated requester / finance / dept. head 404, guest -> login; inline vs download;
  items, classification and confidential flag saved; type/category mismatch rejected (form and model); add-after-filing, history
  line and policy; `attachments:move-to-private` moves a legacy public file, dry run, missing file, re-run).

## Decisions to confirm
See `docs/OPEN_QUESTIONS.md` (rows 10-13 are new).

## Known gaps
- **Nothing in this phase has been run.** Most likely places for a first failure: the Alpine code in `repairs/create` and
  `components/file-picker` (category filtering, `DataTransfer`), finfo answers for the tiny test fixtures (webp / docx / xlsx),
  and `Storage::fake('local')` with `response()` streaming in the download tests.
- Browsers cannot keep chosen files after a validation error, so the person must pick them again (the form says so).
- Inline PDFs/images are sent with `nosniff` and a private cache header, but no CSP sandbox.
- The `{ticket}`/`{attachment}` 404 hides existence from users who cannot see the ticket; a user who can see the ticket but may not
  add files gets a 403 on the upload form.
- iPhone photos in HEIC format are not allowed (Safari normally converts them to JPEG for file inputs).
- The old `repair_requests` model (`RepairRequest::photoUrl()`) still points at the public disk; that table is unused since Phase 1.
- There is no virus scanning and no delete/replace file action.

## Click through (after the commands above)
1. Log in as **requester@** on a phone-width browser (360px, or DevTools device mode). New request: choose **Materials**; the
   category list shrinks to materials categories; the "Items needed" block appears. Add 2 items.
2. Tap **Take photo** / **Choose files**: pick 2 photos and a PDF. Names and sizes appear; remove one and add it back.
   Tick **Mark as confidential**. Pick a date that is 5 working days away. **SEND REQUEST**.
3. Tickets -> open the new ticket: 3 attachments with icon, size, uploader, time; click the PDF (opens in the browser) and
   Download. Items and the lock badge are shown.
4. Copy a file link (`/attachments/ID`). Open it while logged in as **requester2@** -> "Not Found". As **maintenance@** and
   **president@** -> opens. Logged out -> login page. Open `/storage/ticket-attachments/...` -> Not Found.
5. As **requester@**: on the ticket detail, "Add files", upload a PDF -> History shows "Added 1 file (Request file): ...".
   As **maintenance@** the "What are these files?" menu appears (Signed evidence, Work photo).
6. Try bad files: `shell.php.jpg`, a PNG renamed `.jpg`, a 6 MB photo, 6 files at once -> a clear message under the file box.
7. Have old files? `php artisan attachments:move-to-private --dry-run`, then without `--dry-run`. Files disappear from
   `storage/app/public/ticket-attachments` and appear in `storage/app/private/ticket-attachments/YYYY/MM`.
