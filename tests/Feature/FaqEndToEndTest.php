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