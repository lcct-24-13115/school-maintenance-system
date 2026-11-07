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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Help & FAQ Phase 5B: searches with no answer are logged, and the FAQ manager shows what to write next. */
class FaqInsightsTest extends TestCase
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

    // ---------- logging ----------

    public function test_a_search_with_no_result_is_logged_in_lowercase(): void
    {
        $this->faq('How do I sign in?');

        $this->actingAs($this->user('requester'))->get('/help?q='.urlencode('  Wi-Fi   PASSWORD '))->assertOk();

        $miss = FaqSearchMiss::firstOrFail();
        $this->assertSame('wi-fi password', $miss->term);
        $this->assertSame(1, $miss->count);
        $this->assertNotNull($miss->last_seen_at);
    }

    public function test_the_same_term_from_another_visit_adds_one(): void
    {
        $this->actingAs($this->user('requester'));

        $this->get('/help?q=projector')->assertOk();
        $this->flushSession();
        $this->get('/help?q=PROJECTOR')->assertOk();

        $this->assertSame(1, FaqSearchMiss::count());
        $this->assertSame(2, FaqSearchMiss::firstOrFail()->count);
    }

    public function test_reloading_the_page_in_the_same_visit_does_not_inflate_the_count(): void
    {
        $this->actingAs($this->user('requester'));

        $this->get('/help?q=projector')->assertOk();
        $this->get('/help?q=projector')->assertOk();
        $this->get('/help?q=projector')->assertOk();

        $this->assertSame(1, FaqSearchMiss::firstOrFail()->count);
    }

    public function test_a_search_that_finds_something_is_not_logged(): void
    {
        $this->faq('How do I sign in?');

        $this->actingAs($this->user('requester'))->get('/help?q=sign')->assertOk();
        $this->get('/help')->assertOk();

        $this->assertSame(0, FaqSearchMiss::count());
    }

    public function test_a_topic_with_no_match_is_not_a_miss_when_other_topics_match(): void
    {
        $this->faq('How do I sign in?', ['topic' => 'Getting started']);
        $this->faq('Where is my invoice?', ['topic' => 'Billing', 'sort_order' => 20]);

        $this->actingAs($this->user('requester'))->get('/help?q=sign&topic=Billing')->assertOk();

        $this->assertSame(0, FaqSearchMiss::count());
    }

    public function test_a_visitor_search_is_logged_too(): void
    {
        $this->get('/help-public?q=parking')->assertOk();

        $this->assertSame('parking', FaqSearchMiss::firstOrFail()->term);
    }

    public function test_a_search_that_matches_only_hidden_or_other_role_entries_counts_as_a_miss(): void
    {
        $this->faq('Custodian secret sauce', ['audience' => ['custodian']]);
        $this->faq('Hidden secret sauce', ['is_published' => false, 'sort_order' => 20]);

        $this->actingAs($this->user('requester'))->get('/help?q=secret+sauce')->assertOk();

        $this->assertSame('secret sauce', FaqSearchMiss::firstOrFail()->term);
    }

    public function test_the_log_keeps_no_user_data(): void
    {
        $this->actingAs($this->user('requester'))->get('/help?q=projector')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['id', 'term', 'count', 'last_seen_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('faq_search_misses')
        );

        $row = FaqSearchMiss::firstOrFail()->getAttributes();
        $this->assertStringNotContainsString('requester', json_encode($row));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($row));
    }

    public function test_terms_that_look_personal_are_not_stored(): void
    {
        $this->actingAs($this->user('requester'));

        foreach (['juan@school.edu.ph', 'https://example.com/x', 'www.example.com', '09171234567', 'a'] as $term) {
            $this->flushSession();
            $this->get('/help?q='.urlencode($term))->assertOk();
        }

        $this->assertSame(0, FaqSearchMiss::count());
    }

    public function test_the_term_is_cut_at_100_characters(): void
    {
        $this->actingAs($this->user('requester'))->get('/help?q='.str_repeat('a', 300))->assertOk();

        $this->assertSame(100, mb_strlen(FaqSearchMiss::firstOrFail()->term));
    }

    // ---------- the FAQ manager panel ----------

    public function test_the_manager_shows_the_most_helpful_and_not_helpful_entries(): void
    {
        $a = $this->faq('Entry that helps a lot', ['sort_order' => 10]);
        $b = $this->faq('Entry that confuses people', ['sort_order' => 20]);
        $c = $this->faq('Entry nobody rated', ['sort_order' => 30]);

        foreach (['requester', 'finance', 'custodian'] as $who) {
            FaqFeedback::create(['faq_id' => $a->id, 'user_id' => $this->user($who)->id, 'helpful' => true]);
        }
        FaqFeedback::create(['faq_id' => $b->id, 'user_id' => $this->user('requester')->id, 'helpful' => false]);
        FaqFeedback::create(['faq_id' => $b->id, 'user_id' => $this->user('finance')->id, 'helpful' => false]);
        FaqFeedback::create(['faq_id' => $b->id, 'user_id' => $this->user('custodian')->id, 'helpful' => true]);

        $response = $this->actingAs($this->user('maintenance'))->get('/admin/faq')->assertOk();

        $insights = $response->viewData('insights');

        $this->assertSame([$a->id, $b->id], $insights['helpful']->pluck('id')->all());
        $this->assertSame(3, (int) $insights['helpful']->first()->helpful_count);
        $this->assertSame([$b->id], $insights['not_helpful']->pluck('id')->all());
        $this->assertSame(2, (int) $insights['not_helpful']->first()->not_helpful_count);

        $response->assertSee('Most helpful')->assertSee('Not helpful')->assertSee('Searches with no answer');
    }

    public function test_the_manager_shows_the_top_ten_searches_with_no_answer(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            FaqSearchMiss::create(['term' => "term {$i}", 'count' => $i, 'last_seen_at' => now()]);
        }

        $response = $this->actingAs($this->user('maintenance'))->get('/admin/faq')->assertOk();

        $terms = $response->viewData('insights')['misses']->pluck('term')->all();

        $this->assertCount(10, $terms);
        $this->assertSame('term 12', $terms[0]);
        $this->assertSame('term 3', $terms[9]);
        $response->assertSee('term 12')->assertDontSee('term 2<', false)->assertDontSee('term 1<', false);
    }

    public function test_a_logged_term_is_shown_escaped(): void
    {
        FaqSearchMiss::create(['term' => '<b>bold</b>', 'count' => 1, 'last_seen_at' => now()]);

        $this->actingAs($this->user('maintenance'))->get('/admin/faq')->assertOk()
            ->assertSee('&lt;b&gt;bold&lt;/b&gt;', false)
            ->assertDontSee('<b>bold</b>', false);
    }

    public function test_the_empty_panel_says_so(): void
    {
        $this->actingAs($this->user('maintenance'))->get('/admin/faq')->assertOk()
            ->assertSee('Every search found something so far.')
            ->assertSee('No &ldquo;helpful&rdquo; votes yet.', false);
    }

    public function test_other_roles_still_cannot_open_the_manager(): void
    {
        FaqSearchMiss::create(['term' => 'secret term', 'count' => 5, 'last_seen_at' => now()]);

        foreach (['finance', 'president', 'requester', 'custodian'] as $who) {
            $this->actingAs($this->user($who))->get('/admin/faq')->assertForbidden();
        }
    }
}
