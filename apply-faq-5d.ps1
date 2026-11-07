# apply-faq-5d.ps1  -  Help & FAQ Phase 5D (accessibility, print, docs, end-to-end test)
# Patakbuhin sa project folder (katabi ng "artisan"):  powershell -ExecutionPolicy Bypass -File .\apply-faq-5d.ps1
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'artisan'))) { throw 'Walang "artisan" dito. cd muna sa project folder.' }
$utf8 = New-Object System.Text.UTF8Encoding $false

function Fix-Eol([string]$s, [bool]$crlf) {
    $s = $s.Replace("`r`n", "`n")
    if ($crlf) { return $s.Replace("`n", "`r`n") }
    return $s
}

function Put-File([string]$rel, [string]$text) {
    $path = Join-Path $root $rel
    $dir = Split-Path $path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($path, (Fix-Eol $text $true), $utf8)
    Write-Host "  isinulat  $rel"
}

function Patch-File([string]$rel, [string]$old, [string]$new) {
    $path = Join-Path $root $rel
    if (-not (Test-Path $path)) { throw "Walang file: $rel" }
    $text = [System.IO.File]::ReadAllText($path)
    $crlf = $text.Contains("`r`n")
    $old = Fix-Eol $old $crlf
    $new = Fix-Eol $new $crlf
    if ($text.Contains($new)) { Write-Host "  na-apply na  $rel"; return }
    $first = $text.IndexOf($old)
    if ($first -lt 0) { throw "Hindi nahanap ang linya sa $rel :`n$old" }
    if ($text.IndexOf($old, $first + 1) -ge 0) { throw "Higit sa isa ang nahanap sa $rel :`n$old" }
    [System.IO.File]::WriteAllText($path, $text.Replace($old, $new), $utf8)
    Write-Host "  na-edit   $rel"
}

# Idinadagdag sa dulo ng file, isang beses lang (may $marker na sa file = laktawan).
function Append-Once([string]$rel, [string]$marker, [string]$add) {
    $path = Join-Path $root $rel
    if (-not (Test-Path $path)) { throw "Walang file: $rel" }
    $text = [System.IO.File]::ReadAllText($path)
    if ($text.Contains($marker)) { Write-Host "  na-apply na  $rel"; return }
    $crlf = $text.Contains("`r`n")
    $add = Fix-Eol $add $crlf
    $eol = if ($crlf) { "`r`n" } else { "`n" }
    if (-not $text.EndsWith("`n")) { $add = $eol + $add }
    [System.IO.File]::AppendAllText($path, $add, $utf8)
    Write-Host "  dinagdagan  $rel"
}

Write-Host 'Mga bagong file:'
$n0 = @'
/*
 * Help & FAQ accessibility helpers (Phase 5D). No library. The page works without this file:
 *   - <details>/<summary> is already keyboard friendly (Tab to a question, Enter or Space to open it).
 *   - this file only adds two things:
 *       1. printing: every answer is opened for the printer and put back as it was afterwards
 *       2. a link such as /help#faq-12 opens that answer (the feedback redirect and bookmarks use this)
 */
(function () {
  'use strict';

  var reopened = [];
  var printing = false;

  function items() {
    return Array.prototype.slice.call(document.querySelectorAll('details.help-item'));
  }

  function openAllForPrint() {
    if (printing) {
      return;   // beforeprint and the print media query may both fire: remember only the first time
    }
    printing = true;
    reopened = [];
    items().forEach(function (d) {
      if (!d.open) {
        d.open = true;
        reopened.push(d);
      }
    });
  }

  function restoreAfterPrint() {
    if (!printing) {
      return;
    }
    printing = false;
    reopened.forEach(function (d) {
      d.open = false;
    });
    reopened = [];
  }

  window.addEventListener('beforeprint', openAllForPrint);
  window.addEventListener('afterprint', restoreAfterPrint);

  if (window.matchMedia) {
    var mql = window.matchMedia('print');
    var onChange = function (e) {
      if (e.matches) {
        openAllForPrint();
      } else {
        restoreAfterPrint();
      }
    };
    if (mql.addEventListener) {
      mql.addEventListener('change', onChange);
    } else if (mql.addListener) {
      mql.addListener(onChange);
    }
  }

  function openFromHash() {
    var m = /^#(faq-\d+)$/.exec(window.location.hash || '');
    if (!m) {
      return;
    }
    var el = document.getElementById(m[1]);
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
    }
  }

  openFromHash();
  window.addEventListener('hashchange', openFromHash);
})();
'@
Put-File 'public/js/help-a11y.js' $n0

$n1 = @'
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
'@
Put-File 'docs/FAQ_MODULE.md' $n1

$n2 = @'
<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\FaqSearchMiss;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help & FAQ Phase 5D: the whole story from start to end, with real requests and no shortcuts.
 *
 *   1. the Maintenance Admin writes three entries in the FAQ manager
 *   2. a requester searches (one search finds an answer, one finds nothing) and votes
 *   3. the admin sees the vote and the search that found nothing
 *   4. the admin hides the entry the requester voted on
 *   5. the requester no longer sees it (the cached list must not hold it back) and cannot vote on it any more
 *   6. a visitor who is not signed in sees only what is public
 *   7. the admin publishes it again and it comes back
 */
class FaqEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const Q_PUBLIC = 'How do I sign in to the portal';

    private const Q_MEMBERS = 'Where do I report a broken printer';

    private const Q_CUSTODIAN = 'How do I count the stock';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    /** @return list<string> the questions the Help page of the last response shows */
    private function shown($response): array
    {
        return $response->viewData('entries')->pluck('question')->values()->all();
    }

    public function test_the_whole_life_of_a_help_entry(): void
    {
        // ---- 1. the admin writes three entries in the FAQ manager ----
        $this->actingAs($this->user('maintenance'));

        $this->post(route('admin.faq.store'), [
            'topic' => 'Getting started',
            'question' => self::Q_PUBLIC,
            'answer' => 'Use your school e-mail and your password.',
            'everyone' => '1',
            'is_public' => '1',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.faq.store'), [
            'topic' => 'Repairs',
            'question' => self::Q_MEMBERS,
            'answer' => 'Open New repair request and pick the printer category.',
            'everyone' => '1',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.faq.store'), [
            'topic' => 'Inventory',
            'question' => self::Q_CUSTODIAN,
            'answer' => 'Open the stock page and press Count.',
            'audience' => ['custodian'],
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, Faq::count());
        $members = Faq::where('question', self::Q_MEMBERS)->firstOrFail();
        $custodian = Faq::where('question', self::Q_CUSTODIAN)->firstOrFail();
        $public = Faq::where('question', self::Q_PUBLIC)->firstOrFail();

        // ---- 2. a requester reads, searches and votes ----
        $this->actingAs($this->user('requester'));

        $page = $this->get(route('help.index'))->assertOk();
        $this->assertEqualsCanonicalizing([self::Q_PUBLIC, self::Q_MEMBERS], $this->shown($page));
        $page->assertDontSee(self::Q_CUSTODIAN);

        $found = $this->get(route('help.index', ['q' => 'printer']))->assertOk();
        $this->assertSame([self::Q_MEMBERS], $this->shown($found));

        $nothing = $this->get(route('help.index', ['q' => 'Wifi Password']))->assertOk();
        $this->assertSame([], $this->shown($nothing));
        $nothing->assertSee('No answers found for', false);

        $this->postJson(route('help.feedback', $members), ['helpful' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'helpful' => true]);

        // an entry meant for another role cannot be rated, and nothing is written
        $this->postJson(route('help.feedback', $custodian), ['helpful' => 1])->assertNotFound();

        $this->assertSame(1, FaqFeedback::count());

        // ---- 3. the admin sees the vote and the search that found nothing ----
        $this->actingAs($this->user('maintenance'));

        $manager = $this->get(route('admin.faq.index'))->assertOk();
        $insights = $manager->viewData('insights');

        $this->assertSame([$members->id], $insights['helpful']->pluck('id')->all());
        $this->assertSame(1, (int) $insights['helpful']->first()->helpful_count);
        $this->assertSame([], $insights['not_helpful']->pluck('id')->all());
        $this->assertSame(['wifi password'], $insights['misses']->pluck('term')->all());
        $this->assertSame(1, (int) $insights['misses']->first()->count);
        $manager->assertSee('wifi password');

        // the requester never searched for anything personal, and nothing about who searched is kept
        $this->assertSame(1, FaqSearchMiss::count());

        // ---- 4. the admin hides the entry the requester voted on ----
        $this->patch(route('admin.faq.toggle', $members))->assertRedirect();
        $this->assertFalse($members->fresh()->is_published);

        // ---- 5. the requester no longer sees it, even though the list was cached a moment ago ----
        $this->actingAs($this->user('requester'));

        $after = $this->get(route('help.index'))->assertOk();
        $this->assertSame([self::Q_PUBLIC], $this->shown($after));

        $search = $this->get(route('help.index', ['q' => 'printer']))->assertOk();
        $this->assertSame([], $this->shown($search));

        $this->postJson(route('help.feedback', $members), ['helpful' => 0])->assertNotFound();
        $this->assertTrue(FaqFeedback::where('faq_id', $members->id)->firstOrFail()->helpful);   // the old vote is untouched

        // ---- 6. a visitor who is not signed in sees only what is public ----
        $this->app['auth']->forgetGuards();   // the person above is signed in; the next request is a visitor

        $this->get(route('help.index'))->assertRedirect(route('login'));

        $guest = $this->get(route('help.public'))->assertOk();
        $this->assertSame([self::Q_PUBLIC], $this->shown($guest));

        $guestSearch = $this->get(route('help.public', ['q' => 'printer']))->assertOk();
        $this->assertSame([], $this->shown($guestSearch));

        $this->postJson(route('help.public.feedback', $members), ['helpful' => 1])->assertNotFound();      // hidden
        $this->postJson(route('help.public.feedback', $custodian), ['helpful' => 1])->assertNotFound();    // not for everyone
        $this->postJson(route('help.public.feedback', $public), ['helpful' => 1])->assertOk();             // public: allowed

        $this->assertSame(2, FaqFeedback::count());   // the requester's vote and the visitor's vote, nothing else

        // ---- 7. the admin publishes it again and it comes back for the requester, but not for the visitor ----
        $this->actingAs($this->user('maintenance'));
        $this->patch(route('admin.faq.toggle', $members))->assertRedirect();
        $this->assertTrue($members->fresh()->is_published);

        $this->actingAs($this->user('requester'));
        $back = $this->get(route('help.index'))->assertOk();
        $this->assertEqualsCanonicalizing([self::Q_PUBLIC, self::Q_MEMBERS], $this->shown($back));

        $this->app['auth']->forgetGuards();
        $guestAgain = $this->get(route('help.public'))->assertOk();
        $this->assertSame([self::Q_PUBLIC], $this->shown($guestAgain));
    }
}
'@
Put-File 'tests/Feature/FaqEndToEndTest.php' $n2

$n3 = @'
<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Help & FAQ Phase 5D: keyboard, screen reader, phone and print support that can be checked without a browser. */
class FaqAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function entries(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Faq::create([
                'topic' => 'Getting started',
                'audience' => ['all'],
                'is_public' => true,
                'question' => "Question number {$i} of the list",
                'answer' => "Answer number {$i}.",
                'sort_order' => $i * 10,
                'is_published' => true,
            ]);
        }
    }

    public function test_the_questions_are_native_details_and_summary_elements(): void
    {
        $this->entries(2);

        $html = $this->actingAs($this->user('requester'))->get(route('help.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<details class="help-item"'));
        $this->assertSame(2, substr_count($html, '<summary>'));
    }

    public function test_back_to_top_is_not_shown_on_a_short_list(): void
    {
        $this->entries(2);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertDontSee('Back to top')
            ->assertSee('id="help-top"', false);
    }

    public function test_back_to_top_shows_on_a_long_list_and_points_at_the_page(): void
    {
        $this->entries(6);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertSee('Back to top')
            ->assertSee('href="#help-top"', false)
            ->assertSee('tabindex="-1"', false);
    }

    public function test_the_print_line_and_the_accessibility_script_are_on_the_page_for_everybody(): void
    {
        $this->entries(1);

        $this->get(route('help.public'))
            ->assertOk()
            ->assertSee('help-print-head', false)
            ->assertSee('js/help-a11y.js', false);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertSee('help-print-head', false)
            ->assertSee('js/help-a11y.js', false);
    }

    public function test_the_manager_buttons_that_only_show_an_icon_say_which_entry_they_are_for(): void
    {
        $this->entries(2);

        $this->actingAs($this->user('maintenance'))->get(route('admin.faq.index'))
            ->assertOk()
            ->assertSee('aria-label="Move up: Question number 2 of the list"', false)
            ->assertSee('aria-label="Move down: Question number 1 of the list"', false)
            ->assertSee('aria-label="Delete: Question number 1 of the list"', false);
    }

    public function test_the_stylesheet_has_focus_phone_and_print_rules(): void
    {
        $css = (string) file_get_contents(public_path('css/help.css'));

        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('overflow-wrap:anywhere', $css);
        $this->assertStringContainsString('.help-top', $css);

        $manager = (string) file_get_contents(public_path('css/phase6.css'));
        $this->assertStringContainsString('td[data-label="Actions"]', $manager);
    }

    public function test_the_accessibility_script_is_plain_javascript_with_no_dependency(): void
    {
        $js = (string) file_get_contents(public_path('js/help-a11y.js'));

        $this->assertStringContainsString('beforeprint', $js);
        $this->assertStringContainsString('afterprint', $js);
        $this->assertStringNotContainsString('import ', $js);
        $this->assertStringNotContainsString('require(', $js);
    }
}
'@
Put-File 'tests/Feature/FaqAccessibilityTest.php' $n3

Write-Host ''
Write-Host 'Mga dinagdagan sa dulo:'
$a4 = @'

/* ====================================================================================================
   PHASE 5D: keyboard, phone and print (no new library; plain CSS)
   ==================================================================================================== */
html:has(.help-page){scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){
  html:has(.help-page){scroll-behavior:auto}
  .help-item>summary::after{transition:none}
}

/* the page itself can receive focus from "Back to top", without showing a box */
.help-page{scroll-margin-top:96px;min-width:0;overflow-wrap:anywhere}
.help-page:focus{outline:none}

/* visible focus for everything that can be tabbed to (the search box keeps its own ring above) */
.help-page a:focus-visible,
.help-page button:focus-visible,
.help-chip:focus-visible,
.auth-help-link:focus-visible{outline:3px solid var(--blue);outline-offset:2px}
.help-item>summary:focus-visible{outline:3px solid var(--blue);outline-offset:-3px;border-radius:16px;background:var(--surface-2)}

/* bigger tap targets, and the arrow is decoration (empty alternative text where the browser supports it) */
.help-item>summary{min-height:44px}
.help-item>summary::after{content:"\f078";content:"\f078" / ""}
.help-chip{min-height:40px}
.help-fb{min-height:40px}

/* phones: nothing may push the page sideways */
.help-item>summary,.help-answer,.help-chip,.help-contact a{overflow-wrap:anywhere}
.help-chip{max-width:100%;min-width:0}
.help-chips,.help-search,.help-actions{min-width:0}
.help-answer img,.help-answer table,.help-answer pre{max-width:100%}
.help-answer pre,.help-answer code{white-space:pre-wrap;word-break:break-word}
.auth-card:has(.help-page){min-width:0;max-width:100%}
@media (max-width:575.98px){
  .help-search-box{flex:1 1 100%}
  .help-chips{gap:6px}
  .help-more{padding:16px}
}

/* Back to top */
.help-top{display:flex;justify-content:flex-end;margin:14px 0 0}
.help-top a{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 16px;border-radius:999px;border:1.5px solid var(--line);background:#fff;color:var(--navy);font-size:13.5px;font-weight:700;text-decoration:none}
.help-top a:hover{border-color:var(--blue);color:var(--deep);text-decoration:none}

/* the line that only shows on paper */
.help-print-head{display:none}

/* PRINT: every answer open, no menus, no search, no vote buttons */
@media print{
  @page{margin:14mm}
  body:has(.help-page){background:#fff!important;color:#000}
  body:has(.help-page) :is(.sidebar,.sb-scrim,.topbar,.hero-m,.auth-help-link,.auth-credit,.auth-logo,.auth-brand){display:none!important}
  body:has(.help-page) .main{margin:0!important}
  body:has(.help-page) :is(.stage,.content,.auth-card){margin:0!important;padding:0!important;max-width:none!important;width:auto!important;border:0!important;box-shadow:none!important;background:none!important}
  .help-page{max-width:none}
  .help-print-head{display:block;margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid #777;font-size:11pt;color:#000}
  .help-back,.help-manage,.help-search,.help-chips,.help-status,.help-feedback,.help-top,.help-actions,.help-empty .btn,
  .help-more .btn,.help-signin{display:none!important}
  .help-intro{color:#000}
  .help-group h2{color:#000;break-after:avoid}
  .help-item{box-shadow:none;border:1px solid #888;border-radius:6px;background:#fff;break-inside:avoid}
  .help-item>summary{color:#000;padding:8px 10px}
  .help-item>summary::after{content:none!important}
  .help-answer{padding:0 10px 10px;color:#000}
  .help-item mark{background:none;font-weight:700}
  .help-more{border:0;background:none;padding:10px 0 0;break-inside:avoid}
  .help-more h2{color:#000}
  .help-more>p,.help-contact b{color:#000}
  .help-contact i{display:none}
}
@media print{
  .help-item::details-content{content-visibility:visible!important}
}
'@
Append-Once 'public/css/help.css' 'PHASE 5D: keyboard, phone and print' $a4

$a5 = @'

/* PHASE 5D: the FAQ manager on a phone must not scroll sideways */
.p6-wrap{min-width:0;max-width:100%}
@media (max-width:767.98px){
  table.stack td[data-label="Actions"]{white-space:normal!important;text-align:left!important}
  table.stack td[data-label="Actions"]>form,table.stack td[data-label="Actions"]>a{margin:0 6px 6px 0}
}
'@
Append-Once 'public/css/phase6.css' 'PHASE 5D: the FAQ manager on a phone' $a5

$a6 = @'
| 85 | FAQ P2 | What does the **"Still need help?"** box show, and to whom? | To everybody who can read Help (signed in and visitors): office hours and contact e-mail from the settings (a blank one is not shown). The "Send a repair request" button only shows to people who may file a request; a visitor gets a sign-in link instead. | `/admin/settings` (`help_office_hours`, `help_contact_email`), `help/_still-need-help.blade.php` |
| 86 | FAQ P3 | Does an entry an admin edited survive `faq:seed`? | Yes. Editing the content of a starter entry makes it custom; `faq:seed` only adds missing starter entries and updates starter ones nobody edited. It never deletes. Hiding or moving an entry is not an edit. | `Faq::booted()`, `FaqSeeder::sync()` |
| 87 | FAQ P5A | Who may vote "Was this helpful?", and how often? | Anybody who may read the entry. Signed in: one vote per person per entry (voting again changes it). Visitor: one per session per entry, up to 20 entries per session. A hidden entry, or one for another role, answers 404. | `FaqFeedbackController`, `FaqFeedback::GUEST_MAX_ENTRIES` |
| 88 | FAQ P5A | What is kept about a **visitor** who votes? | Only a hash of a random session token and a keyed hash of the IP address. No name, no raw IP address, no browser details. | `FaqFeedback::guestHash()`, `FaqFeedback::ipHash()` |
| 89 | FAQ P5B | Which searches are written down as "no answer", and what is kept? | A search that finds nothing among the entries that person may read. The words only (lowercase, max 100 characters), counted once per visit, with the last time. Never stored: who searched; terms under 2 characters; terms with `@`, `://`, `www.` or 6+ digits. | `FaqSearchMiss::normalize()`, `FaqSearchMiss::recordOnce()` |
| 90 | FAQ P5B | Who sees the votes and the no-answer list? | The Maintenance Admin (`manage_faq`), at the top of the FAQ manager: top 5 helpful, top 5 not helpful, top 10 searches with no answer. | `FaqAdminController::insights()` |
| 91 | FAQ P5C | How soon does the Help page show an admin's change? | At once for changes made in the app (the cache is cleared). A change made directly in the database waits up to 10 minutes, or run `php artisan cache:clear`. | `App\Support\FaqCache`, `FaqObserver` |
| 92 | FAQ P5C | How many requests are allowed? | Help page for visitors: 60 a minute. Votes: 30 a minute. | `routes/web.php` |
| 93 | FAQ P5C | What limits do the two Help texts have? | Office hours: plain text, up to 200 characters, no `<` `>` and no line break. E-mail: a real address, up to 120 characters, saved in lowercase. A blank value removes it; one mistake saves neither. | `Admin\SettingsController` |
| 94 | FAQ P5D | What can a person do with the keyboard, on a phone and on paper? | Keyboard: Tab to a question, Enter or Space opens it, visible focus on everything. Phone: nothing scrolls sideways. After 5 answers a "Back to top" link shows. Print: menus, search and vote buttons are hidden and every answer is opened. No new npm package. | `help/_content.blade.php`, `public/css/help.css`, `public/js/help-a11y.js` |
| 95 | FAQ P5D | Are the icon-only buttons readable by a screen reader? | Yes. In the FAQ manager the arrows and the delete button name their entry ("Move up: <question>", "Delete: <question>"). | `admin/faq/index.blade.php` |
| 96 | FAQ P5D | Does a link to one answer open it? | Yes: `/help#faq-12` opens answer 12 (the page also does this after a vote without JavaScript). | `public/js/help-a11y.js` |
| 97 | FAQ P5D | How long are votes and no-answer search terms kept? | Forever: nothing deletes them. NOT BUILT YET: a retention period (see also 9). CONFIRM WITH CLIENT. | `faq_feedback`, `faq_search_misses` |
'@
Append-Once 'docs/OPEN_QUESTIONS.md' '| 85 | FAQ P2 |' $a6

Write-Host ''
Write-Host 'Mga na-edit na linya:'
$o7 = @'
<div class="help-page">
'@
$n7 = @'
<div class="help-page" id="help-top" tabindex="-1">
    <p class="help-print-head"><b>LCCT Maintenance - Help &amp; FAQ</b> &middot; printed {{ now()->format('F j, Y') }}@if ($topic) &middot; Topic: {{ $topic }}@endif @if ($q !== '') &middot; Search: {{ $q }}@endif</p>
'@
Patch-File 'resources/views/help/_content.blade.php' $o7 $n7

$o8 = @'
@include('help._still-need-help')
'@
$n8 = @'
@if ($count >= 5)
        <p class="help-top"><a href="#help-top"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Back to top</a></p>
    @endif

    @include('help._still-need-help')
'@
Patch-File 'resources/views/help/_content.blade.php' $o8 $n8

$o9 = @'
<script src="{{ asset('js/help-feedback.js') }}?v={{ @filemtime(public_path('js/help-feedback.js')) }}" defer></script>
'@
$n9 = @'
<script src="{{ asset('js/help-feedback.js') }}?v={{ @filemtime(public_path('js/help-feedback.js')) }}" defer></script>
    <script src="{{ asset('js/help-a11y.js') }}?v={{ @filemtime(public_path('js/help-a11y.js')) }}" defer></script>
'@
Patch-File 'resources/views/help/_content.blade.php' $o9 $n9

$o10 = @'
title="Move up" aria-label="Move up"
'@
$n10 = @'
title="Move up" aria-label="Move up: {{ $faq->question }}"
'@
Patch-File 'resources/views/admin/faq/index.blade.php' $o10 $n10

$o11 = @'
title="Move down" aria-label="Move down"
'@
$n11 = @'
title="Move down" aria-label="Move down: {{ $faq->question }}"
'@
Patch-File 'resources/views/admin/faq/index.blade.php' $o11 $n11

$o12 = @'
title="Delete" aria-label="Delete"
'@
$n12 = @'
title="Delete" aria-label="Delete: {{ $faq->question }}"
'@
Patch-File 'resources/views/admin/faq/index.blade.php' $o12 $n12

$o13 = @'
## About Laravel
'@
$n13 = @'
**LCCT Help & FAQ:** how it works, who sees what and how to run its tests: [docs/FAQ_MODULE.md](docs/FAQ_MODULE.md)

## About Laravel
'@
Patch-File 'README.md' $o13 $n13

Write-Host ''
Write-Host 'TAPOS. Susunod:  php artisan optimize:clear  tapos  php artisan test --filter=Faq'
