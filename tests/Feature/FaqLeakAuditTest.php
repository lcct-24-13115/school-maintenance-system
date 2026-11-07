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