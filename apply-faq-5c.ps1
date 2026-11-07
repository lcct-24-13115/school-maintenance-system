# apply-faq-5c.ps1  -  Help & FAQ Phase 5C (settings, caching, safety)
# Patakbuhin sa project folder (katabi ng "artisan"):  powershell -ExecutionPolicy Bypass -File .\apply-faq-5c.ps1
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'artisan'))) { throw 'Walang "artisan" dito. cd muna sa project folder.' }
$utf8 = New-Object System.Text.UTF8Encoding $false

function Put-File([string]$rel, [string]$text) {
    $path = Join-Path $root $rel
    $dir = Split-Path $path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($path, $text, $utf8)
    Write-Host "  isinulat  $rel"
}

function Patch-File([string]$rel, [string]$old, [string]$new) {
    $path = Join-Path $root $rel
    if (-not (Test-Path $path)) { throw "Walang file: $rel" }
    $text = [System.IO.File]::ReadAllText($path)
    if ($text.Contains($new)) { Write-Host "  na-apply na  $rel"; return }
    $first = $text.IndexOf($old)
    if ($first -lt 0) { throw "Hindi nahanap ang linya sa $rel :`n$old" }
    if ($text.IndexOf($old, $first + 1) -ge 0) { throw "Higit sa isa ang nahanap sa $rel :`n$old" }
    [System.IO.File]::WriteAllText($path, $text.Replace($old, $new), $utf8)
    Write-Host "  na-edit   $rel"
}


Write-Host 'Mga bagong file / napalitan:'
$c0 = @'
<?php

namespace App\Support;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache of the PUBLISHED Help & FAQ list, one copy per audience (Help & FAQ Phase 5C).
 *
 *  - Audience = "guest" (not signed in) or one role. Two people of the same role may read exactly the same entries,
 *    so they share one copy. The key is built here from the person's role, never from anything in the request,
 *    so one audience can never be handed another audience's copy.
 *  - What is stored is only what that audience may read (published, public for guests, audience checked), as plain
 *    rows. Unpublished entries and entries of other roles are never put into a copy.
 *  - A copy lives 10 minutes. Any admin change clears all copies at once (FaqObserver, plus flush() in the two
 *    places that write without model events: the order buttons and faq:seed).
 *  - Clearing = a new "version" token in the cache; old copies are simply never read again and expire by themselves.
 *    (This works on every cache driver, also "database", which has no tags.)
 */
class FaqCache
{
    public const TTL_MINUTES = 10;

    private const VERSION_KEY = 'faq.list.version';

    /** What this person may read, in display order. Same result as Faq::visibleToFresh(), but cached. @return Collection<int, Faq> */
    public static function visible(?User $user): Collection
    {
        try {
            $rows = Cache::remember(
                self::key($user),
                now()->addMinutes(self::TTL_MINUTES),
                fn () => Faq::visibleToFresh($user)->map(fn (Faq $faq) => $faq->getAttributes())->values()->all(),
            );
        } catch (QueryException) {
            return Faq::visibleToFresh($user);   // the cache table is not migrated yet: skip the cache
        }

        return Faq::hydrate($rows);
    }

    /** Throw away every cached list (all audiences). Call it after ANY change to the faqs table. */
    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (string) Str::uuid());
        } catch (QueryException) {
            // no cache table, so nothing is cached either
        }
    }

    /** "guest", "norole" or "role.<slug>". */
    public static function audienceKey(?User $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        $slug = (string) $user->role?->slug;

        return $slug === '' ? 'norole' : 'role.'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $slug);
    }

    public static function key(?User $user): string
    {
        return 'faq.list.'.self::version().'.'.self::audienceKey($user);
    }

    private static function version(): string
    {
        try {
            return (string) Cache::get(self::VERSION_KEY, '0');
        } catch (QueryException) {
            return '0';
        }
    }
}
'@
Put-File 'app/Support/FaqCache.php' $c0

$c1 = @'
<?php

namespace App\Observers;

use App\Models\Faq;
use App\Support\FaqCache;

/**
 * Help & FAQ Phase 5C: any change to an entry (add, edit, hide / publish, delete) clears the cached lists, so the
 * Help page shows the change at once. Registered in AppServiceProvider.
 * (Writes that skip model events call FaqCache::flush() themselves: FaqAdminController::move() and faq:seed.)
 */
class FaqObserver
{
    public function saved(Faq $faq): void
    {
        FaqCache::flush();
    }

    public function deleted(Faq $faq): void
    {
        FaqCache::flush();
    }
}
'@
Put-File 'app/Observers/FaqObserver.php' $c1

$c2 = @'
<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\FaqSearchMiss;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Help & FAQ page (Phase 2).
 *   GET /help         (help.index)   signed-in people: entries for "all" or their role
 *   GET /help-public  (help.public)  anyone: only entries that are public AND for "all"  (throttle:60,1)
 * Visibility is decided by Faq::isVisibleTo(): unpublished entries and entries of another role never get here.
 *
 * Phase 5C: the list a person may read comes from FaqCache (10 minutes, one copy per audience, cleared on any admin
 * change). The search and the topic counts are done on that list, so a visit costs one query on the faqs table
 * when the copy is cold and none when it is warm: the counts of all topics come from the same list, not from a
 * query per topic.
 */
class FaqController extends Controller
{
    public const MAX_QUERY_LENGTH = 100;

    public function index(Request $request): View|RedirectResponse
    {
        $publicPage = $request->routeIs('help.public');

        // A signed-in person has no use for the visitor page: send them to the real one (keeping the search).
        if ($publicPage && $request->user()) {
            return redirect()->route('help.index', $request->query());
        }

        $user = $publicPage ? null : $request->user();
        $q = self::cleanQuery($request->query('q'));

        $visible = Faq::visibleTo($user);                                      // what this person may read at all (cached)
        $matching = $q === '' ? $visible : self::search($visible, $q);         // ... that matches the search

        // Phase 5B: a search that finds nothing is written down (the words only), so the admin knows what to write next.
        if ($q !== '' && $matching->isEmpty()) {
            FaqSearchMiss::recordOnce($q, $request);
        }

        $counts = $matching->countBy('topic');   // every topic's count in one pass over the list
        $topics = $visible->pluck('topic')->unique()->values()
            ->map(fn (string $name) => ['name' => $name, 'count' => (int) ($counts[$name] ?? 0)]);

        $requested = $request->query('topic');
        $topic = is_string($requested) && $visible->contains('topic', $requested) ? $requested : null;

        $entries = $topic === null ? $matching : $matching->where('topic', $topic)->values();

        return view('help.index', [
            'isGuest' => $user === null,
            'q' => $q,
            'topic' => $topic,
            'topics' => $topics,
            'total' => $matching->count(),
            'entries' => $entries,
            'groups' => $entries->groupBy('topic'),
            'votes' => FaqFeedback::votesFor($entries->pluck('id')->all(), $user, $request),   // Phase 5A: what this reader already voted
        ]);
    }

    /** Trim, collapse spaces, drop control characters, cap at 100 characters. Anything that is not text becomes "". */
    public static function cleanQuery(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $clean = $clean === null ? '' : preg_replace('/\s+/u', ' ', $clean);

        return trim(mb_substr(trim((string) $clean), 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * The entries whose question or answer contains the words (not case sensitive; a typed % or _ is searched as itself).
     * It only ever looks inside the list the person may already read.
     *
     * @param  Collection<int, Faq>  $entries
     * @return Collection<int, Faq>
     */
    private static function search(Collection $entries, string $q): Collection
    {
        $needle = mb_strtolower($q);

        return $entries
            ->filter(fn (Faq $faq) => str_contains(mb_strtolower((string) $faq->question), $needle)
                || str_contains(mb_strtolower((string) $faq->answer), $needle))
            ->values();
    }
}
'@
Put-File 'app/Http/Controllers/FaqController.php' $c2

$c3 = @'
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help & FAQ Phase 5C: the two texts of the "Still need help?" box (office hours, contact e-mail) on /admin/settings.
 * Capability manage_settings, validation, and where the texts show up.
 */
class FaqSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_ROLES = ['requester', 'custodian', 'finance', 'president', 'depthead', 'treasurer'];

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

    private function payload(array $extra = []): array
    {
        return $extra + [
            'sla_hours_routine' => Setting::int('sla_hours_routine'),
            'sla_hours_emergency' => Setting::int('sla_hours_emergency'),
            'reopen_window_days' => Setting::int('reopen_window_days'),
        ];
    }

    private function save(array $extra)
    {
        return $this->actingAs($this->user('maintenance'))->put(route('admin.settings.update'), $this->payload($extra));
    }

    private function defaultHours(): string
    {
        return (string) config('school.settings_defaults.help_office_hours');
    }

    private function defaultEmail(): string
    {
        return (string) config('school.settings_defaults.help_contact_email');
    }

    // ---------- who may change them ----------

    public function test_only_manage_settings_may_open_or_save_the_texts(): void
    {
        foreach (self::OTHER_ROLES as $who) {
            $this->actingAs($this->user($who));

            $this->get(route('admin.settings.edit'))->assertForbidden();
            $this->put(route('admin.settings.update'), $this->payload([
                'help_office_hours' => 'Changed by '.$who,
                'help_contact_email' => $who.'@example.test',
            ]))->assertForbidden();
        }

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
        $this->assertSame(0, AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count());
    }

    public function test_a_visitor_is_sent_to_login_from_the_settings_page(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'), $this->payload(['help_office_hours' => 'x']))->assertRedirect(route('login'));
    }

    // ---------- validation ----------

    public function test_office_hours_may_be_at_most_200_characters(): void
    {
        $this->save(['help_office_hours' => str_repeat('a', 200)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 200), Setting::get('help_office_hours'));

        $this->save(['help_office_hours' => str_repeat('b', 201)])->assertSessionHasErrors('help_office_hours');
        $this->assertSame(str_repeat('a', 200), Setting::get('help_office_hours'));   // the refused one was not saved
    }

    public function test_office_hours_must_be_plain_text(): void
    {
        foreach (['<b>Mon</b> to Fri', 'Mon <script>alert(1)</script>', "Mon\nFri", "Mon\x07Fri"] as $bad) {
            $this->save(['help_office_hours' => $bad])->assertSessionHasErrors('help_office_hours');
        }

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
    }

    public function test_the_contact_email_must_be_a_real_address_of_at_most_120_characters(): void
    {
        foreach (['not an email', 'x@@example.test', 'plain', str_repeat('a', 130).'@example.test'] as $bad) {
            $this->save(['help_contact_email' => $bad])->assertSessionHasErrors('help_contact_email');
        }

        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));

        $this->save(['help_contact_email' => 'help@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('help@example.test', Setting::get('help_contact_email'));
    }

    public function test_one_bad_field_saves_nothing_at_all(): void
    {
        $this->save(['help_office_hours' => 'Good hours', 'help_contact_email' => 'nope'])
            ->assertSessionHasErrors('help_contact_email');

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
    }

    public function test_the_email_is_saved_in_lower_case(): void
    {
        $this->save(['help_contact_email' => 'Help@Example.TEST'])->assertSessionHasNoErrors();

        $this->assertSame('help@example.test', Setting::get('help_contact_email'));
    }

    public function test_blank_fields_go_back_to_the_standard_text(): void
    {
        Setting::put('help_office_hours', 'Custom hours');
        Setting::put('help_contact_email', 'custom@example.test');

        $this->save(['help_office_hours' => '', 'help_contact_email' => ''])->assertSessionHasNoErrors();

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
    }

    // ---------- where they show ----------

    public function test_the_saved_texts_show_in_the_help_box_for_a_signed_in_person_and_for_a_visitor(): void
    {
        $this->save([
            'help_office_hours' => 'Tuesday to Saturday, 10 AM to 3 PM',
            'help_contact_email' => 'desk@example.test',
        ])->assertSessionHasNoErrors();

        // the visitor first (a later actingAs would keep the person signed in)
        $this->app['auth']->forgetGuards();
        $this->get('/help-public')->assertOk()
            ->assertSee('Tuesday to Saturday, 10 AM to 3 PM')
            ->assertSee('href="mailto:desk@example.test"', false);

        $this->actingAs($this->user('requester'))->get('/help')->assertOk()
            ->assertSee('Tuesday to Saturday, 10 AM to 3 PM')
            ->assertSee('href="mailto:desk@example.test"', false);
    }

    // ---------- audit ----------

    public function test_a_change_is_written_to_the_audit_log_and_no_change_is_not(): void
    {
        $this->save(['help_office_hours' => 'New hours'])->assertSessionHasNoErrors();

        $row = AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->latest('id')->firstOrFail();
        $this->assertSame([$this->defaultHours(), 'New hours'], $row->meta['changes']['help_office_hours']);

        $before = AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count();
        $this->save(['help_office_hours' => 'New hours'])->assertSessionHasNoErrors();   // same value again

        $this->assertSame($before, AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count());
    }
}
'@
Put-File 'tests/Feature/FaqSettingsTest.php' $c3

$c4 = @'
<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use App\Support\FaqCache;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Help & FAQ Phase 5C: the cached published list (one copy per audience, 10 minutes), one query for all the topic
 * counts, clearing on every admin change, and the throttle of the visitor page.
 */
class FaqCacheTest extends TestCase
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

    private function faq(string $question, array $over = []): Faq
    {
        return Faq::create(array_merge([
            'topic' => 'Getting started',
            'audience' => ['all'],
            'is_public' => false,
            'question' => $question,
            'answer' => 'An answer.',
            'sort_order' => 10,
            'is_published' => true,
        ], $over));
    }

    /** @return list<string> */
    private function questions(TestResponse $response): array
    {
        return $response->viewData('entries')->pluck('question')->all();
    }

    /** How many queries since the log was cleared touched the faqs table. */
    private function faqQueries(): int
    {
        return collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/\bfaqs\b/', $q['query']) === 1)->count();
    }

    // ---------- the cache ----------

    public function test_a_cold_visit_reads_the_faqs_once_and_a_warm_visit_not_at_all(): void
    {
        $this->faq('A one', ['topic' => 'Alpha', 'sort_order' => 10]);
        $this->faq('A two', ['topic' => 'Alpha', 'sort_order' => 20]);
        $this->faq('B one', ['topic' => 'Beta', 'sort_order' => 30]);
        $this->faq('C one', ['topic' => 'Gamma', 'sort_order' => 40]);

        $this->actingAs($this->user('requester'));
        DB::enableQueryLog();

        $cold = $this->get('/help')->assertOk();
        $this->assertSame(1, $this->faqQueries(), 'all entries and all topic counts come from ONE query');
        $this->assertSame(['Alpha' => 2, 'Beta' => 1, 'Gamma' => 1], $cold->viewData('topics')->pluck('count', 'name')->all());

        DB::flushQueryLog();
        $warm = $this->get('/help')->assertOk();
        $this->assertSame(0, $this->faqQueries());
        $this->assertSame($this->questions($cold), $this->questions($warm));
    }

    public function test_a_search_and_a_topic_filter_use_the_cached_list_too(): void
    {
        $this->faq('How do I sign in?', ['topic' => 'Alpha']);
        $this->faq('Where is my ticket?', ['topic' => 'Beta', 'sort_order' => 20]);

        $this->actingAs($this->user('requester'));
        $this->get('/help')->assertOk();   // warms the copy
        DB::enableQueryLog();

        $this->assertSame(['How do I sign in?'], $this->questions($this->get('/help?q=SIGN')));
        $this->assertSame(['Where is my ticket?'], $this->questions($this->get('/help?topic=Beta')));
        $this->assertSame(0, $this->faqQueries());
    }

    public function test_each_audience_has_its_own_copy(): void
    {
        $this->faq('Public one', ['is_public' => true, 'sort_order' => 10]);
        $this->faq('Signed in one', ['sort_order' => 20]);
        $this->faq('Custodian one', ['audience' => ['custodian'], 'sort_order' => 30]);
        $this->faq('Admin one', ['audience' => ['maintenance_admin'], 'sort_order' => 40]);

        // the visitor first (a later actingAs would keep the person signed in)
        $this->assertSame(['Public one'], $this->questions($this->get('/help-public')));

        $this->assertSame(['Public one', 'Signed in one'], $this->questions($this->actingAs($this->user('requester'))->get('/help')));
        $this->assertSame(['Public one', 'Signed in one', 'Custodian one'], $this->questions($this->actingAs($this->user('custodian'))->get('/help')));
        $this->assertSame(['Public one', 'Signed in one', 'Admin one'], $this->questions($this->actingAs($this->user('maintenance'))->get('/help')));

        // all copies are warm now: nobody gets somebody else's
        $this->assertSame(['Public one', 'Signed in one'], $this->questions($this->actingAs($this->user('requester'))->get('/help')));
        $this->assertSame(['Public one', 'Signed in one', 'Custodian one'], $this->questions($this->actingAs($this->user('custodian'))->get('/help')));
        $this->assertSame(['Public one'], Faq::visibleTo(null)->pluck('question')->all());

        // two people of the same role share one copy
        $this->assertSame(FaqCache::key($this->user('requester')), FaqCache::key($this->user('requester')->fresh()));
        $this->assertNotSame(FaqCache::key($this->user('requester')), FaqCache::key($this->user('custodian')));
        $this->assertNotSame(FaqCache::key(null), FaqCache::key($this->user('requester')));
    }

    public function test_a_copy_lives_ten_minutes(): void
    {
        $this->faq('One');
        $this->actingAs($this->user('requester'));
        $this->get('/help')->assertOk();
        DB::enableQueryLog();

        $this->travel(9)->minutes();
        $this->get('/help')->assertOk();
        $this->assertSame(0, $this->faqQueries(), 'still cached after 9 minutes');

        $this->travel(11)->minutes();
        DB::flushQueryLog();
        $this->get('/help')->assertOk();
        $this->assertSame(1, $this->faqQueries(), 'read again after the 10 minutes ran out');

        $this->travelBack();
    }

    // ---------- clearing ----------

    public function test_the_model_observer_clears_the_copies(): void
    {
        $before = FaqCache::key(null);

        $faq = $this->faq('One', ['is_public' => true]);
        $afterCreate = FaqCache::key(null);
        $this->assertNotSame($before, $afterCreate);

        $faq->update(['answer' => 'Changed.']);
        $afterUpdate = FaqCache::key(null);
        $this->assertNotSame($afterCreate, $afterUpdate);

        $faq->delete();
        $this->assertNotSame($afterUpdate, FaqCache::key(null));
    }

    public function test_every_admin_change_shows_on_the_help_page_at_once(): void
    {
        $admin = $this->user('maintenance');
        $requester = $this->user('requester');
        $see = fn () => $this->questions($this->actingAs($requester)->get('/help'));

        $first = $this->faq('First', ['sort_order' => 10]);
        $second = $this->faq('Second', ['sort_order' => 20]);
        $this->assertSame(['First', 'Second'], $see());

        // add and edit (model events)
        $third = $this->faq('Third', ['sort_order' => 30]);
        $this->assertSame(['First', 'Second', 'Third'], $see());
        $third->update(['question' => 'Third edited']);
        $this->assertSame(['First', 'Second', 'Third edited'], $see());

        // hide and publish again (the buttons of the FAQ manager)
        $this->actingAs($admin)->patch(route('admin.faq.toggle', $second))->assertRedirect();
        $this->assertSame(['First', 'Third edited'], $see());
        $this->actingAs($admin)->patch(route('admin.faq.toggle', $second))->assertRedirect();
        $this->assertSame(['First', 'Second', 'Third edited'], $see());

        // move: the order is written without model events, so the controller clears the copies itself
        $this->actingAs($admin)->post(route('admin.faq.move', $second), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Second', 'First', 'Third edited'], $see());

        // a copy starts hidden: nothing new appears
        $this->actingAs($admin)->post(route('admin.faq.duplicate', $first))->assertRedirect();
        $this->assertSame(['Second', 'First', 'Third edited'], $see());

        // delete
        $this->actingAs($admin)->delete(route('admin.faq.destroy', $first))->assertRedirect();
        $this->assertSame(['Second', 'Third edited'], $see());
    }

    public function test_faq_seed_clears_the_copies_even_though_it_saves_quietly(): void
    {
        $this->seed(FaqSeeder::class);

        $row = collect(require database_path('data/faq_starter.php'))->first(fn (array $r) => (bool) $r[2]);
        $question = $row[3];
        $standardAnswer = $row[4];

        // an old answer, written without model events, then warm the visitor copy with it
        Faq::query()->where('question', $question)->update(['answer' => 'OLD ANSWER']);
        FaqCache::flush();
        $this->assertSame('OLD ANSWER', Faq::visibleTo(null)->firstWhere('question', $question)->answer);

        $result = (new FaqSeeder)->sync();

        $this->assertSame(1, $result['updated']);
        $this->assertSame($standardAnswer, Faq::visibleTo(null)->firstWhere('question', $question)->answer);
    }

    // ---------- throttle ----------

    public function test_the_visitor_page_is_throttled_to_60_a_minute(): void
    {
        $this->assertContains(
            'throttle:60,1,help-public',
            Route::getRoutes()->getByName('help.public')->gatherMiddleware(),
        );

        for ($i = 1; $i <= 60; $i++) {
            $this->get('/help-public')->assertOk();
        }

        $this->get('/help-public')->assertStatus(429);
    }
}
'@
Put-File 'tests/Feature/FaqCacheTest.php' $c4

$c5 = @'
<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\User;
use App\Support\FaqCache;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Help & FAQ Phase 5C: the audit. No endpoint, no search, no filter and no cached copy may give a person an
 * unpublished entry or an entry meant for another role (or, for a visitor, an entry that is not public).
 *
 * Entries (the answers contain a word that appears nowhere else, so a leak is easy to see):
 *   Public visible     published, public, for everyone
 *   Signed in all      published, for everyone, not public
 *   Custodian secret   published, custodian only
 *   Admin only         published, maintenance admin only
 *   Hidden draft       NOT published, for everyone, marked public (a hidden entry must stay hidden even so)
 *   Hidden custodian   NOT published, custodian only
 */
class FaqLeakAuditTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_ROLES = ['requester', 'custodian', 'finance', 'president', 'depthead', 'treasurer'];

    /** @var array<string, Faq> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);

        $this->e['public'] = $this->faq('Public visible', 'Topic Open', 'word-open', ['is_public' => true], 10);
        $this->e['all'] = $this->faq('Signed in all', 'Topic Open', 'word-signedin', [], 20);
        $this->e['custodian'] = $this->faq('Custodian secret', 'Topic Custodian', 'word-custodiansecret', ['audience' => ['custodian']], 30);
        $this->e['admin'] = $this->faq('Admin only', 'Topic Admin', 'word-adminsecret', ['audience' => ['maintenance_admin']], 40);
        $this->e['draft'] = $this->faq('Hidden draft', 'Topic Draft', 'word-draftsecret', ['is_public' => true, 'is_published' => false], 50);
        $this->e['hiddencust'] = $this->faq('Hidden custodian', 'Topic HiddenCust', 'word-hiddencustsecret', ['audience' => ['custodian'], 'is_published' => false], 60);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function faq(string $question, string $topic, string $answer, array $over, int $order): Faq
    {
        return Faq::create(array_merge([
            'topic' => $topic,
            'audience' => ['all'],
            'is_public' => false,
            'question' => $question,
            'answer' => $answer,
            'sort_order' => $order,
            'is_published' => true,
        ], $over));
    }

    /** @return list<string> */
    private function questions(TestResponse $response): array
    {
        return $response->viewData('entries')->pluck('question')->all();
    }

    /** What each person may read. */
    private function expected(string $who): array
    {
        return match ($who) {
            'custodian' => ['Public visible', 'Signed in all', 'Custodian secret'],
            'maintenance' => ['Public visible', 'Signed in all', 'Admin only'],
            default => ['Public visible', 'Signed in all'],
        };
    }

    /** Words that must never be on the page of this person. */
    private function forbiddenFor(string $who): array
    {
        $words = ['word-draftsecret', 'word-hiddencustsecret', 'Hidden draft', 'Hidden custodian', 'Topic Draft', 'Topic HiddenCust'];

        if ($who !== 'custodian') {
            array_push($words, 'word-custodiansecret', 'Custodian secret', 'Topic Custodian');
        }

        if ($who !== 'maintenance') {
            array_push($words, 'word-adminsecret', 'Admin only', 'Topic Admin');
        }

        return $words;
    }

    // ---------- the pages ----------

    public function test_a_visitor_sees_only_the_public_entry(): void
    {
        $response = $this->get('/help-public')->assertOk();

        $this->assertSame(['Public visible'], $this->questions($response));

        foreach (['word-signedin', 'Signed in all', 'word-custodiansecret', 'word-adminsecret', 'word-draftsecret', 'word-hiddencustsecret', 'Topic Draft', 'Topic Custodian', 'Topic Admin'] as $word) {
            $response->assertDontSee($word);
        }
    }

    public function test_every_role_sees_exactly_its_entries_and_nothing_else_in_the_html(): void
    {
        foreach (['requester', 'custodian', 'maintenance', 'finance', 'president', 'depthead', 'treasurer'] as $who) {
            $response = $this->actingAs($this->user($who))->get('/help')->assertOk();

            $this->assertSame($this->expected($who), $this->questions($response), $who);

            foreach ($this->forbiddenFor($who) as $word) {
                $response->assertDontSee($word);
            }
        }
    }

    public function test_the_second_visit_from_the_cached_copy_leaks_nothing_either(): void
    {
        foreach (['requester', 'custodian', 'maintenance'] as $who) {
            $this->actingAs($this->user($who))->get('/help')->assertOk();   // warms the copy of this role
        }

        foreach (['requester', 'custodian', 'maintenance', 'requester'] as $who) {
            $response = $this->actingAs($this->user($who))->get('/help')->assertOk();

            $this->assertSame($this->expected($who), $this->questions($response), $who);

            foreach ($this->forbiddenFor($who) as $word) {
                $response->assertDontSee($word);
            }
        }
    }

    public function test_a_cached_copy_holds_only_what_its_audience_may_read(): void
    {
        $people = ['guest' => null, 'requester' => $this->user('requester'), 'custodian' => $this->user('custodian'), 'maintenance' => $this->user('maintenance')];

        foreach ($people as $user) {
            Faq::visibleTo($user);
        }

        $stored = fn (?User $user) => collect(Cache::get(FaqCache::key($user)))->pluck('question')->all();

        $this->assertSame(['Public visible'], $stored($people['guest']));
        $this->assertSame($this->expected('requester'), $stored($people['requester']));
        $this->assertSame($this->expected('custodian'), $stored($people['custodian']));
        $this->assertSame($this->expected('maintenance'), $stored($people['maintenance']));
    }

    // ---------- search and filter ----------

    public function test_search_never_finds_a_hidden_or_other_role_entry(): void
    {
        $this->actingAs($this->user('requester'));

        foreach (['word-custodiansecret', 'word-adminsecret', 'word-draftsecret', 'word-hiddencustsecret', 'Hidden draft', 'Custodian secret', 'secret'] as $term) {
            $response = $this->get('/help?q='.urlencode($term))->assertOk();

            $this->assertSame([], $this->questions($response), $term);
            $this->assertSame(0, $response->viewData('total'), $term);
            $this->assertNotContains('Topic Custodian', $response->viewData('topics')->pluck('name')->all(), $term);
        }
    }

    public function test_a_topic_that_only_hidden_or_other_role_entries_have_is_ignored(): void
    {
        $this->actingAs($this->user('requester'));

        foreach (['Topic Custodian', 'Topic Admin', 'Topic Draft', 'Topic HiddenCust'] as $topic) {
            $response = $this->get('/help?topic='.urlencode($topic))->assertOk();

            $this->assertNull($response->viewData('topic'), $topic);
            $this->assertSame($this->expected('requester'), $this->questions($response), $topic);
            $this->assertSame(['Topic Open'], $response->viewData('topics')->pluck('name')->all(), $topic);
        }
    }

    public function test_the_visitor_cannot_search_or_filter_into_signed_in_entries(): void
    {
        $response = $this->get('/help-public?q='.urlencode('word-signedin'))->assertOk();
        $this->assertSame([], $this->questions($response));

        $response = $this->get('/help-public?topic='.urlencode('Topic Custodian'))->assertOk();
        $this->assertNull($response->viewData('topic'));
        $this->assertSame(['Public visible'], $this->questions($response));
    }

    // ---------- the feedback endpoints ----------

    public function test_a_signed_in_person_cannot_vote_on_what_they_cannot_read(): void
    {
        $this->actingAs($this->user('requester'));

        foreach (['custodian', 'admin', 'draft', 'hiddencust'] as $key) {
            $this->postJson('/help/'.$this->e[$key]->id.'/feedback', ['helpful' => 1])->assertNotFound();
        }

        // the same answer as for an id that does not exist: the route does not say whether the entry exists
        $this->postJson('/help/999999/feedback', ['helpful' => 1])->assertNotFound();

        $this->assertSame(0, FaqFeedback::count());
    }

    public function test_a_visitor_cannot_vote_on_what_is_not_public(): void
    {
        foreach (['all', 'custodian', 'admin', 'draft', 'hiddencust'] as $key) {
            $this->postJson('/help-public/'.$this->e[$key]->id.'/feedback', ['helpful' => 1])->assertNotFound();
        }

        $this->postJson('/help-public/999999/feedback', ['helpful' => 1])->assertNotFound();

        $this->assertSame(0, FaqFeedback::count());
    }

    public function test_a_visitor_can_vote_on_the_public_entry(): void
    {
        $this->postJson('/help-public/'.$this->e['public']->id.'/feedback', ['helpful' => 1])->assertOk();

        $this->assertSame(1, FaqFeedback::count());
    }

    // ---------- the admin routes ----------

    public function test_a_person_without_manage_faq_gets_the_same_403_for_a_hidden_entry_and_for_an_id_that_does_not_exist(): void
    {
        $ids = [$this->e['draft']->id, $this->e['hiddencust']->id, 999999];
        $before = Faq::count();

        foreach (self::OTHER_ROLES as $who) {
            $this->actingAs($this->user($who));

            foreach ($ids as $id) {
                $this->get("/admin/faq/{$id}/edit")->assertForbidden();
                $this->put("/admin/faq/{$id}", ['question' => 'Changed by a stranger'])->assertForbidden();
                $this->patch("/admin/faq/{$id}/visibility")->assertForbidden();
                $this->post("/admin/faq/{$id}/move", ['direction' => 'up'])->assertForbidden();
                $this->post("/admin/faq/{$id}/duplicate")->assertForbidden();
                $this->delete("/admin/faq/{$id}")->assertForbidden();
            }
        }

        $this->assertSame($before, Faq::count());
        $this->assertFalse($this->e['draft']->fresh()->is_published);
        $this->assertSame('Hidden draft', $this->e['draft']->fresh()->question);
    }

    public function test_the_maintenance_admin_still_gets_404_for_an_id_that_does_not_exist(): void
    {
        $this->actingAs($this->user('maintenance'));

        $this->get('/admin/faq/999999/edit')->assertNotFound();
        $this->get('/admin/faq/'.$this->e['draft']->id.'/edit')->assertOk();   // the admin may open a hidden entry
    }

    public function test_a_visitor_is_sent_to_login_from_the_admin_routes(): void
    {
        $id = $this->e['draft']->id;

        $this->get("/admin/faq/{$id}/edit")->assertRedirect(route('login'));
        $this->patch("/admin/faq/{$id}/visibility")->assertRedirect(route('login'));
        $this->delete("/admin/faq/{$id}")->assertRedirect(route('login'));
    }

    // ---------- the list of endpoints ----------

    /**
     * Every route that has "help" or "faq" in its address. If you add one, this test fails on purpose: check that
     * it cannot give out an unpublished or other-role entry, then add it to the list.
     */
    public function test_the_list_of_help_and_faq_endpoints_is_the_one_that_was_audited(): void
    {
        $found = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'help') || str_contains($route->uri(), 'faq'))
            ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri())
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            'GET /help',
            'POST /help/{faq}/feedback',
            'GET /help-public',
            'POST /help-public/{faq}/feedback',
            'GET /admin/faq',
            'GET /admin/faq/create',
            'POST /admin/faq',
            'GET /admin/faq/{faq}/edit',
            'PUT /admin/faq/{faq}',
            'PATCH /admin/faq/{faq}/visibility',
            'POST /admin/faq/{faq}/move',
            'POST /admin/faq/{faq}/duplicate',
            'DELETE /admin/faq/{faq}',
        ], $found);
    }
}
'@
Put-File 'tests/Feature/FaqLeakAuditTest.php' $c5

$c6 = @'
FAQ PHASE 5C - Settings, caching at safety
==========================================

TERMINAL (PowerShell) sa project folder, hal. C:\laragon\www\school-maintenance-system:

  cd C:\laragon\www\school-maintenance-system
  git add -A ; git commit -m "Before FAQ 5C"
  powershell -ExecutionPolicy Bypass -File .\apply-faq-5c.ps1
  php artisan optimize:clear
  php artisan test --filter=FaqSettingsTest
  php artisan test --filter=FaqCacheTest
  php artisan test --filter=FaqLeakAuditTest
  php artisan test --filter=Faq

Ang apply-faq-5c.ps1 ay ISANG file lang (walang zip, walang extract). Ilagay ito sa project folder (katabi ng "artisan")
bago patakbuhin. Gumagawa ito ng mga bagong file at nag-e-edit ng ilang linya sa mga umiiral. Kung may hindi
mahanap na linya, titigil ito at sasabihin kung alin (walang kalahating pagbabago sa file na iyon).
Pwede mo itong patakbuhin ulit: nilalaktawan nito ang naka-apply na.

WALANG MIGRATION. WALANG "npm install". WALANG "npm run build".

ANO ANG NAGAWA
  1. SETTINGS: ang "Office hours" at "Contact e-mail" ng "Still need help?" box ay nasa /admin/settings na (mula pa sa
     Help 2C, capability manage_settings). Dinagdagan ng mas mahigpit na validation:
       - office hours: hanggang 200 character, plain text lang (bawal ang < >, bawal ang line break / control character)
       - contact e-mail: dapat tunay na e-mail, hanggang 120 character, sine-save sa lower case
       - blangko = balik sa standard na teksto; may mali = walang na-save kahit isang field
     Ang mga teksto ay lumalabas sa box para sa signed-in at sa bisita (naka-escape, hindi HTML).
  2. EAGER COUNTS: lahat ng bilang bawat topic ay galing sa iisang listahan (isang query sa faqs kapag malamig ang cache,
     wala kapag mainit), hindi isang query bawat topic.
  3. CACHE: ang published list ay naka-cache ng 10 minuto, ISANG kopya BAWAT AUDIENCE (bisita, o bawat role).
     Nililinis ang lahat ng kopya sa anumang admin change: add, edit, hide/publish, delete (FaqObserver), at sa
     move up/down at faq:seed na walang model event (tahasang FaqCache::flush()).
  4. THROTTLE: /help-public ay throttle:60,1 (may prefix na "help-public" para hindi maghalo ang bilang nito sa
     ibang pahina ng bisita, hal. forgot-password).
  5. AUDIT NG LEAK:
       - Sa /admin/faq/{id}/... ang taong walang manage_faq ay laging 403, kahit hidden na entry o id na wala
         (dati: 403 kung may id, 404 kung wala = nalalaman kung may entry). Ang admin ay 404 pa rin sa id na wala.
       - Ang feedback route ay 404 sa hidden / ibang role / hindi public, kapareho ng id na wala.
       - Ang search at ?topic= ay sa loob lang ng listahang puwedeng basahin ng tao; ang cache ay walang nilalamang
         unpublished o ibang role.
       - May test na naglilista ng lahat ng route na may "help" o "faq": kapag may nagdagdag ng bago, mag-fail ito
         para masuri muna.

PAGBABAGO SA ASAL (alamin)
  - Ang search ay ginagawa na sa PHP sa naka-cache na listahan (hindi na SQL LIKE). Pareho ang resulta: hindi sensitibo
    sa laki ng letra, ang % at _ ay hinahanap bilang sarili nila.
  - Pagkatapos ng admin change, ang pagbabago ay makikita agad (nililinis ang cache). Kung babaguhin mo ang faqs table
    nang direkta sa database (phpMyAdmin / SQL), hintayin ang 10 minuto o patakbuhin ang:  php artisan cache:clear

Bago (bagong file):
  app/Support/FaqCache.php
  app/Observers/FaqObserver.php
  tests/Feature/FaqSettingsTest.php
  tests/Feature/FaqCacheTest.php
  tests/Feature/FaqLeakAuditTest.php

Napalitan ang buong file:
  app/Http/Controllers/FaqController.php

Maliliit na dagdag sa (linya lang):
  app/Models/Faq.php                                   <- visibleTo() ay gumagamit na ng cache (visibleToFresh() ang tunay na query)
  app/Providers/AppServiceProvider.php                 <- Faq::observe() at ang {faq} binding
  routes/web.php                                       <- throttle sa /help-public
  app/Http/Controllers/Admin/SettingsController.php    <- validation ng 2 teksto
  app/Http/Controllers/Admin/FaqAdminController.php    <- flush pagkatapos ng move
  database/seeders/FaqSeeder.php                       <- flush pagkatapos ng faq:seed

KUNG MAY MAG-FAIL NA TEST: i-copy ang buong error message at ipadala mo sa akin.
'@
Put-File 'APPLY-FAQ-5C.txt' $c6

Write-Host 'Mga pagbabago sa umiiral na file:'
$o0 = @'
    public static function visibleTo(?User $user): Collection
'@
$n0 = @'
    public static function visibleTo(?User $user): Collection
    {
        return \App\Support\FaqCache::visible($user);   // Phase 5C: cached 10 minutes per audience, cleared on any admin change
    }

    /** The real query behind visibleTo(). Only FaqCache calls it (on a cache miss). @return Collection<int, Faq> */
    public static function visibleToFresh(?User $user): Collection
'@
Patch-File 'app/Models/Faq.php' $o0 $n0

$o1 = @'
use App\Models\MaintenanceRequest;
'@
$n1 = @'
use App\Models\Faq;
use App\Models\MaintenanceRequest;
'@
Patch-File 'app/Providers/AppServiceProvider.php' $o1 $n1

$o2 = @'
use App\Observers\UserObserver;
'@
$n2 = @'
use App\Observers\FaqObserver;
use App\Observers\UserObserver;
'@
Patch-File 'app/Providers/AppServiceProvider.php' $o2 $n2

$o3 = @'
        User::observe(UserObserver::class);
'@
$n3 = @'
        User::observe(UserObserver::class);

        // Help & FAQ Phase 5C: any change to an entry clears the cached Help lists.
        Faq::observe(FaqObserver::class);

        // Every {faq} in a URL: a person who may not manage the FAQ gets 403 on the admin routes BEFORE the entry is
        // looked up, so a hidden entry and an id that does not exist look the same to them (no way to probe ids).
        Route::bind('faq', function (string $value) {
            if (request()->routeIs('admin.faq.*')) {
                abort_unless(request()->user()?->canManageFaq(), 403, 'You do not have access to this page.');
            }

            return Faq::query()->where('id', $value)->firstOrFail();
        });
'@
Patch-File 'app/Providers/AppServiceProvider.php' $o3 $n3

$o4 = @'
Route::get('/help-public', [\App\Http\Controllers\FaqController::class, 'index'])->name('help.public');
'@
$n4 = @'
// 60 visits a minute per visitor. The third part ("help-public") keeps its counter apart from the other visitor routes.
Route::get('/help-public', [\App\Http\Controllers\FaqController::class, 'index'])->middleware('throttle:60,1,help-public')->name('help.public');
'@
Patch-File 'routes/web.php' $o4 $n4

$o5 = @'
            'help_office_hours' => ['nullable', 'string', 'max:200'],
'@
$n5 = @'
            'help_office_hours' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail) {
                if (preg_match('/[<>\x00-\x1F\x7F]/u', (string) $value) === 1) {
                    $fail('Office hours must be plain text: no < or > and no line breaks.');
                }
            }],
'@
Patch-File 'app/Http/Controllers/Admin/SettingsController.php' $o5 $n5

$o6 = @'
            'help_contact_email' => ['nullable', 'email', 'max:120'],
'@
$n6 = @'
            'help_contact_email' => ['nullable', 'string', 'email', 'max:120'],
'@
Patch-File 'app/Http/Controllers/Admin/SettingsController.php' $o6 $n6

$o7 = @'
            $new = trim((string) ($data[$key] ?? ''));
'@
$n7 = @'
            $new = trim((string) ($data[$key] ?? ''));

            if ($key === 'help_contact_email') {
                $new = mb_strtolower($new);   // so "Help@School.ph" and "help@school.ph" are the same address
            }
'@
Patch-File 'app/Http/Controllers/Admin/SettingsController.php' $o7 $n7

$o8 = @'
$this->audit(AuditLogger::FAQ_REORDERED,
'@
$n8 = @'
\App\Support\FaqCache::flush();   // the order was written without model events (Phase 5C cache)
            $this->audit(AuditLogger::FAQ_REORDERED,
'@
Patch-File 'app/Http/Controllers/Admin/FaqAdminController.php' $o8 $n8

$o9 = @'
        return $result;
'@
$n9 = @'
        // saveQuietly() above sends no model events, so the cached Help lists are cleared here (Phase 5C).
        if ($result['added'] > 0 || $result['updated'] > 0) {
            \App\Support\FaqCache::flush();
        }

        return $result;
'@
Patch-File 'database/seeders/FaqSeeder.php' $o9 $n9

Write-Host ''
Write-Host 'TAPOS. Susunod:  php artisan optimize:clear  tapos  php artisan test --filter=Faq'
