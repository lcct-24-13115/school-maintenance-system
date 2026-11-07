# Help & FAQ module: how the pieces fit

The Help & FAQ page answers common questions without a ticket. The Maintenance Admin writes the answers in the
FAQ manager; everybody else reads them. This page follows ONE entry from the writer to the reader and back to the
writer (votes and searches that found nothing).

```
FAQ manager (/admin/faq)            Maintenance Admin writes, orders, hides            <- FaqAdminController
   |  saved to table faqs  ->  FaqObserver clears the cache (all audiences)
   v
Help page  /help (signed in)  or  /help-public (visitor)                               <- FaqController
   |  list = FaqCache::visible()  (10 minutes, one copy per audience)
   |  search + topic chips work on that list; typing also filters live (help-filter.js)
   |
   +--> "Was this helpful?" Yes / No  ->  table faq_feedback                           <- FaqFeedbackController
   +--> a search with no result       ->  table faq_search_misses (words only)         <- FaqSearchMiss
   v
FAQ manager, top of the page:  Most helpful | Not helpful | Searches with no answer    <- FaqAdminController::insights()
```

## Pages and who sees them

| Page | URL | Who | Others |
|---|---|---|---|
| Help page (signed in) | `/help` | every signed-in role: entries for "everyone" or for their role | - |
| Help page (visitor) | `/help-public` | anybody: only entries that are public AND for everyone. A signed-in person is sent to `/help`. | - |
| FAQ manager | `/admin/faq` (+ create, edit, hide, move, duplicate, delete) | Maintenance Admin (`manage_faq`) | 403 |
| Vote | `POST /help/{faq}/feedback`, `POST /help-public/{faq}/feedback` | anybody who may read that entry | 404 |
| Settings texts | `/admin/settings` (`help_office_hours`, `help_contact_email`) | Maintenance Admin (`manage_settings`) | 403 |

An entry the person may not read (hidden, other role, not public for a visitor) answers **404** on the vote route and
simply does not appear in the list or the search, so nobody can learn that it exists. In the FAQ manager a person
without `manage_faq` always gets **403**, whatever the id.

The capability lives in `config/permissions.php` (`manage_faq`). To let another role write FAQ entries, add it there.

## An entry

| Field | Meaning |
|---|---|
| `topic` | The group (chip) it appears under. Typing a new topic in the form creates it. |
| `audience` | `["all"]` or role slugs. "Everyone" wins over the role boxes. |
| `is_public` | May be read before signing in. Only possible when the audience is everyone (the form and the server both refuse otherwise). |
| `question`, `answer` | Question 5-255 characters, unique inside its topic. Answer 5-5000 characters. |
| `is_published` | Hidden entries are shown to nobody, only in the FAQ manager. |
| `sort_order` | Order inside a topic (arrow buttons). |
| `source` | `starter` (made by `faq:seed`) or `custom` (written or edited by an admin). |

The answer is **plain text with a small safe format**: blank line = new paragraph, `- item` = bullet, `1. step` = numbered
step, `**bold**`, and `[label](/page)` for a link to a page of this site. Everything else is escaped (`App\Support\FaqText`),
so an answer can never carry a script, an image or an outside link.

## Starter questions

- Written in `database/data/faq_starter.php` (`faq_starter/part1.php`, `part2.php`).
- `php artisan faq:seed` adds what is missing and updates starter entries that nobody edited. It never deletes, and it
  never touches an entry an admin wrote or edited (editing the content of a starter entry makes it `custom`; hiding it or
  moving it does not). Safe to run again and again.

## Search

- Done in PHP on the cached list the person may already read, so it can never reach another role's entries.
- Not case sensitive; `%` and `_` are searched as themselves; at most 100 characters.
- Works without JavaScript (a plain GET form). With JavaScript, typing also narrows the entries on the page; Enter searches all.
- A search that finds **nothing** is written to `faq_search_misses` so the admin knows what to write next (see Privacy).

## Votes ("Was this helpful?")

- Signed in: one vote per person per entry; voting again changes it.
- Visitor: one vote per browser session per entry, up to 20 different entries per session; changing an earlier vote is always allowed.
- Works without JavaScript (the form posts and returns to the entry). Throttle: 30 requests a minute.
- The admin sees the top 5 "helpful", the top 5 "not helpful" and the top 10 searches with no answer at the top of `/admin/faq`.

## Cache and speed

- `FaqCache` keeps the published list for 10 minutes, one copy per audience (visitor, or one per role). The key is built from
  the person's role, never from the request.
- Any admin change clears every copy: add, edit, hide / publish, delete (`FaqObserver`), and move up / down and `faq:seed`
  (they save without model events, so they call `FaqCache::flush()` themselves).
- If somebody edits the `faqs` table directly (phpMyAdmin, SQL), wait 10 minutes or run `php artisan cache:clear`.
- `/help-public` is throttled to 60 requests a minute per visitor.

## Privacy

- **Votes by visitors** store only a hash of a random session token and a keyed hash of the IP address. No name, no raw IP address.
- **Searches with no answer** store the words only: lowercased, spaces collapsed, at most 100 characters, counted once per
  visit. Nothing about who searched. Not stored at all: a term shorter than 2 characters, or one that contains `@`, `://`,
  `www.`, or 6 or more digits (an e-mail address, a link, a phone or ID number).
- A search counts as "no answer" when it finds nothing **among the entries that person may read**.
- Only the Maintenance Admin sees votes and the no-answer list.
- Nothing is deleted automatically (see open question 97).
- The confidential-ticket rules do not apply here: FAQ entries never contain ticket data.

## Settings texts

"Still need help?" shows **office hours** and a **contact e-mail** from `/admin/settings`.

- Office hours: up to 200 characters, plain text (no `<` `>` and no line breaks).
- E-mail: a real address, up to 120 characters, saved in lowercase.
- A blank value removes it. Any mistake saves nothing at all.
- Both are shown escaped, to signed-in people and visitors.

## Links from other pages

`<x-help-link topic="Reports and records" row />` puts a "Help with this page" link on a page, already filtered to one topic.
It shows only to a signed-in person who may read at least one published entry of that topic, so it can never lead to an
empty page.

## Keyboard, phone and print (Phase 5D)

- Each question is a native `<details>` / `<summary>`: Tab to it, Enter or Space opens it. No JavaScript is needed.
- Every link and button has a visible focus ring (`:focus-visible`). Question rows are at least 44 px high.
- The arrow on a question is decoration; the manager's icon-only buttons say which entry they belong to
  (`aria-label="Move up: <question>"`, `"Delete: <question>"`).
- Long words and links wrap, so the page does not scroll sideways on a phone (Help page and FAQ manager).
- "Back to top" appears after 5 or more answers and moves focus to the top of the page.
- Smooth scrolling is turned off for people who asked their system for less motion.
- Printing: menus, search, topic chips and vote buttons are hidden, **every answer is opened** (`public/js/help-a11y.js`,
  put back afterwards) and a heading line with the date is printed. The contact lines of "Still need help?" stay on paper.
- A link such as `/help#faq-12` opens that answer.
- No new npm package. `help-a11y.js` is plain JavaScript; `npm install` and `npm run build` are not needed.

## Audit log

Every change in the FAQ manager is written to the audit log: `faq_created`, `faq_updated`, `faq_published`, `faq_hidden`,
`faq_reordered`, `faq_deleted`. Changes to the two settings texts are logged with the other settings.

## Tests

| File | What it proves |
|---|---|
| `FaqModelTest`, `FaqContentTest` | who may read an entry; the safe answer format; the starter content |
| `FaqPageTest` | the Help page for visitors and signed-in roles, search, topics |
| `FaqAdminTest` | the FAQ manager and its 403s |
| `FaqFeedbackTest`, `FaqInsightsTest` | votes, the no-answer list, privacy of both |
| `FaqSettingsTest`, `FaqCacheTest` | the two texts; cache per audience and clearing |
| `FaqLeakAuditTest` | no route, search or cache copy gives a person an entry they may not read; lists every `help` / `faq` route |
| `FaqAccessibilityTest` | native details, Back to top, icon-only labels, focus / print / phone rules |
| `FaqEndToEndTest` | the whole story: admin writes, requester searches and votes, admin sees both, admin hides, requester and visitor no longer see it |

Run only this module: `php artisan test --filter=Faq`. Run everything: `php artisan test`.

## Deploying

```
php artisan migrate        # tables faqs, faq_feedback, faq_search_misses (and the cache table)
php artisan faq:seed       # the starter questions (safe to repeat)
php artisan optimize:clear
```

There is no scheduled job for this module.

## Not built yet

- Deleting old votes and no-answer terms automatically (open question 97).
- Answers in more than one language.