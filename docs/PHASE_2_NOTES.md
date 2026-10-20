# Phase 2 - Access control + ticket tracking pages (Oct 20, 2026)

> **Not tested.** This phase was written in an environment with no PHP, no Composer and no network, so
> `php artisan test`, `migrate:fresh --seed`, `vendor/bin/pint` and a browser check were **not run**.
> Everything below describes what the code is written to do. Run the commands under "Apply" first and
> send me any failure (see "Known gaps").

## Apply
```
php artisan migrate:fresh --seed      (no new migration this phase; reseed because TicketSeeder changed)
npm run dev
php artisan serve
php artisan test
vendor/bin/pint app config database routes tests
```
No new Composer or NPM packages.
Logins (password `password`): requester@, requester2@, requester3@, maintenance@, maintenance2@, maintenance3@,
finance@, president@, depthead@, treasurer@, custodian@ `lcctanauan.edu.ph`

## What changed
- **Permission matrix** `config/permissions.php` (role slug => capabilities) and `User::can_do('capability')`.
  Inactive accounts can do nothing.
- **`App\Policies\TicketPolicy`** (registered in `AppServiceProvider`): `viewAny`, `view`, `create`, `flagEmergency`,
  `clearEmergency`, `authorize`, `assign`, `work`, `close`, `addAttachment`. `create` and `clearEmergency` are small
  additions to the list you gave me.
- **Visibility**: `MaintenanceRequest::scopeVisibleTo($user)` and `isVisibleTo($user)` (same rule, one for queries,
  one for a single ticket). Confidential = requester, assignee, or `view_confidential`.
  Used in: the new list, ticket detail, maintenance board (+ its accept/complete actions), finance/president/requester
  dashboard counts, and alerts (`FormNotifier` no longer sends a ticket alert to someone who may not see that ticket).
- **404, not 403**: every `{ticket}` in a URL is resolved through `visibleTo()` (route binding in `AppServiceProvider`),
  so a hidden ticket looks exactly like a ticket that does not exist.
- **Emergency rule**: only `flag_emergency` users can set the flag from the form. Anyone else who picks it files a
  routine ticket, and the history gets "Requester asked for emergency review: <reason>". They see
  "Ask for emergency review" instead of "Emergency". The 5-working-day exemption now applies only to people who can flag.
  `TicketWorkflow::open()` writes the note (data key `emergency_review_reason`).
- **`GET /tickets`** (`tickets.index`): queue order, table on desktop / cards on phones, search `q` (ticket no partial,
  description, location, reason, requester name), filters (date range, status, category, assignee, department,
  emergency only), "Clear filters", empty state, 15 per page with filters kept in the page links.
- **`GET /tickets/{ticket}`** (`tickets.show`): all fields, people, dates, items, attachment names (no download yet),
  full history timeline, and an empty "Actions" panel for Phase 4.
- **Navigation**: "Tickets" for everyone; "New request" for roles in `school.filing_roles`.
  Board cards have a "View details & history" link; dashboard cards link to the pre-filtered list.
- **`school.allow_public_registration`** (env `ALLOW_PUBLIC_REGISTRATION`, default true): when false, `/register` is a 404
  and the Register links are hidden.
- **TicketSeeder**: emergency flags in the sample data are now set by a Maintenance Admin (a requester can no longer flag).

### Changes you did not list (please check)
- Filter "Only tickets I filed" (`mine=1`) so a Department Head's "My tickets" dashboard card matches its list.
- Dashboard cards are now `['count' => n, 'url' => ...]` instead of plain numbers.
- Board accept/complete also check the `work` capability (before: role only).
- Bug fix found while testing logins: Dept. Heads and the Treasurer were sent to `requester.dashboard` after login but that
  route only allowed the Requester role (403). It now allows requester, department_head and treasurer.
- The repair form is gated by `school.filing_roles` (default = every role, so behaviour is unchanged).

### Test changes
- `TicketFoundationTest::test_an_emergency_repair_form_flags_the_ticket_with_a_reason` was changed **on purpose**: it now
  files as a Maintenance Admin, because a requester can no longer set the flag (old rule: anyone could).
- New: `tests/Feature/TicketAccessTest.php` (matrix, 404s, board/dashboard/alert leaks, emergency rule, list order,
  search, every filter, pagination, detail history, registration flag, scope-vs-policy agreement over the sample data).

## Decisions to confirm
See `docs/OPEN_QUESTIONS.md` (rows 1-7 are new).

## Known gaps
- **Nothing in this phase has been run.** Most likely places for a first failure: Blade syntax in `tickets/index` and
  `tickets/show`, a test that assumes sample-data details, and the notification test (first test of alerts).
- Attachment download, file storage and the "signed evidence" close rule are Phase 3. Actions on the detail page are Phase 4.
- "Authorized by you" on the President dashboard still counts every authorized ticket (unchanged Phase 1 behaviour) and has no link.
- The filter panel is hidden with Alpine; without JavaScript only the search box is usable.
- Search uses SQL `LIKE` (a `%` or `_` typed by the user acts as a wildcard).

## Click through (after the commands above)
1. Log in as **requester@**: Tickets shows only your tickets; "New request" opens the form; tick the emergency option and
   submit with a valid date -> ticket is routine and its History shows "Requester asked for emergency review".
2. Log in as **requester2@**: open a ticket number of requester@ in the URL (`/tickets/1`) -> 404.
3. **depthead@**: sees Elementary tickets, no confidential ones.
4. **finance@**: sees everything except the 3 confidential tickets; dashboard cards open a filtered list.
5. **maintenance@** and **president@**: see confidential tickets (lock badge); maintenance board cards open "View details & history".
6. **treasurer@ / custodian@**: Tickets page is empty (empty state).
7. In Tickets: try search (partial ticket number, a word, a requester name), each filter, "Clear filters", page 2 keeps filters.
8. Phone width (360px): list shows cards, no sideways scrolling; buttons are easy to tap.
9. Set `ALLOW_PUBLIC_REGISTRATION=false` in `.env` -> `/register` shows "Not Found" and the Register links disappear.
