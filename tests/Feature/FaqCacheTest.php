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