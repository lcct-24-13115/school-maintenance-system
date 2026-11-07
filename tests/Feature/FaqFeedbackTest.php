<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Help & FAQ Phase 5A: "Was this helpful?" votes (signed-in people and guests). */
class FaqFeedbackTest extends TestCase
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

    private function url(Faq $faq): string
    {
        return '/help/'.$faq->id.'/feedback';
    }

    private function publicUrl(Faq $faq): string
    {
        return '/help-public/'.$faq->id.'/feedback';
    }

    // ---------- signed-in people ----------

    public function test_a_signed_in_person_can_vote_helpful(): void
    {
        $faq = $this->faq('How do I sign in?');

        $this->actingAs($this->user('requester'))
            ->postJson($this->url($faq), ['helpful' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'helpful' => true]);

        $row = FaqFeedback::firstOrFail();
        $this->assertSame($faq->id, $row->faq_id);
        $this->assertSame($this->user('requester')->id, $row->user_id);
        $this->assertTrue($row->helpful);
        $this->assertNull($row->session_hash);
        $this->assertNull($row->ip_hash);
    }

    public function test_voting_again_updates_the_same_vote(): void
    {
        $faq = $this->faq('How do I sign in?');
        $this->actingAs($this->user('requester'));

        $this->postJson($this->url($faq), ['helpful' => 1])->assertOk();
        $this->postJson($this->url($faq), ['helpful' => 0])->assertOk()->assertJson(['helpful' => false]);
        $this->postJson($this->url($faq), ['helpful' => 0])->assertOk();

        $this->assertSame(1, FaqFeedback::count());
        $this->assertFalse(FaqFeedback::firstOrFail()->helpful);
    }

    public function test_each_person_has_their_own_vote(): void
    {
        $faq = $this->faq('How do I sign in?');

        $this->actingAs($this->user('requester'))->postJson($this->url($faq), ['helpful' => 1])->assertOk();
        $this->actingAs($this->user('finance'))->postJson($this->url($faq), ['helpful' => 0])->assertOk();

        $this->assertSame(2, FaqFeedback::count());
        $this->assertSame(1, FaqFeedback::where('helpful', true)->count());
        $this->assertSame(1, FaqFeedback::where('helpful', false)->count());
    }

    public function test_without_javascript_the_person_is_sent_back_to_the_entry(): void
    {
        $faq = $this->faq('How do I sign in?');

        $this->actingAs($this->user('requester'))
            ->from(route('help.index', ['q' => 'sign']))
            ->post($this->url($faq), ['helpful' => 1])
            ->assertRedirect(route('help.index', ['q' => 'sign']).'#faq-'.$faq->id)
            ->assertSessionHas('help_feedback_id', $faq->id);

        $this->assertSame(1, FaqFeedback::count());
    }

    public function test_the_answer_must_be_yes_or_no(): void
    {
        $faq = $this->faq('How do I sign in?');
        $this->actingAs($this->user('requester'));

        $this->postJson($this->url($faq), [])->assertStatus(422);
        $this->postJson($this->url($faq), ['helpful' => 'maybe'])->assertStatus(422);

        $this->assertSame(0, FaqFeedback::count());
    }

    public function test_a_visitor_must_sign_in_to_use_the_signed_in_route(): void
    {
        $faq = $this->faq('How do I sign in?', ['is_public' => true]);

        $this->post($this->url($faq), ['helpful' => 1])->assertRedirect(route('login'));

        $this->assertSame(0, FaqFeedback::count());
    }

    public function test_nobody_can_rate_an_entry_they_cannot_read(): void
    {
        $custodianOnly = $this->faq('Custodians only', ['audience' => ['custodian']]);
        $hidden = $this->faq('Hidden one', ['is_published' => false, 'sort_order' => 20]);

        $this->actingAs($this->user('requester'));

        $this->postJson($this->url($custodianOnly), ['helpful' => 1])->assertNotFound();
        $this->postJson($this->url($hidden), ['helpful' => 1])->assertNotFound();
        $this->postJson('/help/99999/feedback', ['helpful' => 1])->assertNotFound();

        $this->assertSame(0, FaqFeedback::count());

        // the person it IS for can
        $this->actingAs($this->user('custodian'))->postJson($this->url($custodianOnly), ['helpful' => 1])->assertOk();
        $this->assertSame(1, FaqFeedback::count());
    }

    public function test_the_signed_in_route_is_throttled(): void
    {
        $faq = $this->faq('How do I sign in?');
        $this->actingAs($this->user('requester'));

        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->url($faq), ['helpful' => $i % 2])->assertOk();
        }

        $this->postJson($this->url($faq), ['helpful' => 1])->assertStatus(429);
    }

    // ---------- guests ----------

    public function test_a_guest_can_vote_on_a_public_entry(): void
    {
        $faq = $this->faq('What is this system?', ['is_public' => true]);

        $this->postJson($this->publicUrl($faq), ['helpful' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'helpful' => true]);

        $row = FaqFeedback::firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNotNull($row->session_hash);
        $this->assertSame(64, strlen($row->session_hash));
        $this->assertNotNull($row->ip_hash);
        $this->assertNotSame('127.0.0.1', $row->ip_hash);
        $this->assertSame(64, strlen($row->ip_hash));
    }

    public function test_a_guest_session_has_one_vote_per_entry_and_can_change_it(): void
    {
        $faq = $this->faq('What is this system?', ['is_public' => true]);

        $this->postJson($this->publicUrl($faq), ['helpful' => 1])->assertOk();
        $this->postJson($this->publicUrl($faq), ['helpful' => 0])->assertOk();

        $this->assertSame(1, FaqFeedback::count());
        $this->assertFalse(FaqFeedback::firstOrFail()->helpful);
    }

    public function test_a_guest_session_can_rate_only_so_many_entries(): void
    {
        $faqs = [];
        for ($i = 1; $i <= FaqFeedback::GUEST_MAX_ENTRIES + 1; $i++) {
            $faqs[] = $this->faq("Public question {$i}", ['is_public' => true, 'sort_order' => $i * 10]);
        }

        foreach (array_slice($faqs, 0, FaqFeedback::GUEST_MAX_ENTRIES) as $faq) {
            $this->postJson($this->publicUrl($faq), ['helpful' => 1])->assertOk();
        }

        // one too many: refused
        $this->postJson($this->publicUrl($faqs[FaqFeedback::GUEST_MAX_ENTRIES]), ['helpful' => 1])->assertStatus(429);
        $this->assertSame(FaqFeedback::GUEST_MAX_ENTRIES, FaqFeedback::count());

        // but changing an earlier vote is still fine
        $this->postJson($this->publicUrl($faqs[0]), ['helpful' => 0])->assertOk();
        $this->assertSame(FaqFeedback::GUEST_MAX_ENTRIES, FaqFeedback::count());
    }

    public function test_a_guest_cannot_rate_an_entry_that_is_not_public(): void
    {
        $notPublic = $this->faq('Signed-in only', ['is_public' => false]);
        $forRole = $this->faq('Public but for custodians', ['audience' => ['custodian'], 'is_public' => true, 'sort_order' => 20]);
        $hidden = $this->faq('Public but hidden', ['is_public' => true, 'is_published' => false, 'sort_order' => 30]);

        foreach ([$notPublic, $forRole, $hidden] as $faq) {
            $this->postJson($this->publicUrl($faq), ['helpful' => 1])->assertNotFound();
        }

        $this->assertSame(0, FaqFeedback::count());
    }

    public function test_a_signed_in_person_on_the_visitor_route_is_counted_as_a_visitor(): void
    {
        $faq = $this->faq('What is this system?', ['is_public' => true]);

        $this->actingAs($this->user('requester'))->postJson($this->publicUrl($faq), ['helpful' => 1])->assertOk();

        $this->assertNull(FaqFeedback::firstOrFail()->user_id);
    }

    public function test_the_visitor_route_is_throttled(): void
    {
        $faq = $this->faq('What is this system?', ['is_public' => true]);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->publicUrl($faq), ['helpful' => $i % 2])->assertOk();
        }

        $this->postJson($this->publicUrl($faq), ['helpful' => 1])->assertStatus(429);
    }

    // ---------- the page ----------

    public function test_the_help_page_shows_the_buttons_with_a_csrf_token(): void
    {
        $faq = $this->faq('How do I sign in?', ['is_public' => true]);

        $this->actingAs($this->user('requester'))->get('/help')->assertOk()
            ->assertSee('Was this helpful?')
            ->assertSee('action="'.url($this->url($faq)).'"', false)
            ->assertSee('name="_token"', false);

        $this->app['auth']->forgetGuards();

        $this->get('/help-public')->assertOk()
            ->assertSee('Was this helpful?')
            ->assertSee('action="'.url($this->publicUrl($faq)).'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_the_page_remembers_a_signed_in_vote(): void
    {
        $faq = $this->faq('How do I sign in?');
        $this->actingAs($this->user('requester'));

        $this->get('/help')->assertDontSee('You marked this as helpful.');

        $this->postJson($this->url($faq), ['helpful' => 1])->assertOk();

        $this->get('/help')->assertOk()
            ->assertSee('You marked this as helpful.')
            ->assertSee('aria-pressed="true"', false);

        $this->postJson($this->url($faq), ['helpful' => 0])->assertOk();

        $this->get('/help')->assertOk()->assertSee('You marked this as not helpful.');
    }

    public function test_the_page_remembers_a_guest_vote_in_the_same_session(): void
    {
        $faq = $this->faq('What is this system?', ['is_public' => true]);

        $this->postJson($this->publicUrl($faq), ['helpful' => 1])->assertOk();

        $this->get('/help-public')->assertOk()->assertSee('You marked this as helpful.');
    }

    public function test_the_filter_does_not_search_the_feedback_text(): void
    {
        $js = file_get_contents(public_path('js/help-filter.js'));

        $this->assertStringNotContainsString('text: normalize(el.textContent)', $js);
    }

    public function test_deleting_an_entry_deletes_its_votes(): void
    {
        $faq = $this->faq('How do I sign in?');
        $this->actingAs($this->user('requester'))->postJson($this->url($faq), ['helpful' => 1])->assertOk();

        $faq->delete();

        $this->assertSame(0, FaqFeedback::count());
    }
}
