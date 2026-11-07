<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Faq;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Help & FAQ Phase 3: the FAQ manager (/admin/faq). Capability manage_faq; everyone else gets 403. */
class FaqAdminTest extends TestCase
{
    use RefreshDatabase;

    /** Roles that do NOT have manage_faq (the seeded accounts). */
    private const OTHER_ROLES = ['finance', 'president', 'requester', 'depthead', 'treasurer', 'custodian'];

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

    // ---------------------------------------------------------------- helpers

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function admin(): User
    {
        return $this->user('maintenance');
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

    /** A valid form submission. Pass null to drop a field (for example 'everyone' => null). */
    private function payload(array $over = []): array
    {
        return $over + [
            'topic' => 'Getting started',
            'question' => 'How do I add a new thing?',
            'answer' => 'Open the page and press the button.',
            'everyone' => '1',
            'is_published' => '1',
        ];
    }

    /** @return list<string> the questions of a topic in display order */
    private function order(string $topic): array
    {
        return Faq::where('topic', $topic)->ordered()->pluck('question')->all();
    }

    private function rows(string $action)
    {
        return AuditLog::where('action', $action)->get();
    }

    /** The questions the Help page offers to this person (null = a visitor who is not signed in). @return list<string> */
    private function helpFor(?string $who): array
    {
        if ($who === null) {
            $this->app['auth']->forgetGuards();
            $this->flushSession();

            return $this->get(route('help.public'))->viewData('entries')->pluck('question')->all();
        }

        return $this->actingAs($this->user($who))->get(route('help.index'))->viewData('entries')->pluck('question')->all();
    }

    // ---------------------------------------------------------------- access

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.faq.index'))->assertRedirect(route('login'));
        $this->get(route('admin.faq.create'))->assertRedirect(route('login'));
    }

    public function test_every_role_without_manage_faq_gets_403_on_every_route(): void
    {
        $faq = $this->faq('A question to protect');

        $calls = [
            ['get', route('admin.faq.index'), []],
            ['get', route('admin.faq.create'), []],
            ['get', route('admin.faq.edit', $faq), []],
            ['post', route('admin.faq.store'), $this->payload()],
            ['put', route('admin.faq.update', $faq), $this->payload(['question' => 'Changed by a stranger'])],
            ['patch', route('admin.faq.toggle', $faq), []],
            ['post', route('admin.faq.move', $faq), ['direction' => 'up']],
            ['post', route('admin.faq.duplicate', $faq), []],
            ['delete', route('admin.faq.destroy', $faq), []],
        ];

        foreach (self::OTHER_ROLES as $who) {
            $this->actingAs($this->user($who));

            foreach ($calls as [$method, $url, $data]) {
                $response = $method === 'get' ? $this->get($url) : $this->{$method}($url, $data);
                $response->assertForbidden();
            }
        }

        // nothing changed and nothing was logged
        $this->assertSame(1, Faq::count());
        $this->assertSame('A question to protect', $faq->fresh()->question);
        $this->assertTrue($faq->fresh()->is_published);
        $this->assertSame(0, AuditLog::where('action', 'like', 'faq_%')->count());
    }

    public function test_the_maintenance_admin_can_open_the_pages(): void
    {
        $faq = $this->faq('A question to open');

        $this->actingAs($this->admin());
        $this->get(route('admin.faq.index'))->assertOk();
        $this->get(route('admin.faq.create'))->assertOk();
        $this->get(route('admin.faq.edit', $faq))->assertOk();
    }

    public function test_an_unknown_entry_is_a_404(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.faq.edit', 9999))->assertNotFound();
        $this->delete(route('admin.faq.destroy', 9999))->assertNotFound();
    }

    // ---------------------------------------------------------------- create

    public function test_the_admin_can_create_an_entry(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.faq.store'), $this->payload());

        $response->assertRedirect(route('admin.faq.index', ['topic' => 'Getting started']));
        $response->assertSessionHas('status');

        $faq = Faq::firstOrFail();
        $this->assertSame('Getting started', $faq->topic);
        $this->assertSame('How do I add a new thing?', $faq->question);
        $this->assertSame('Open the page and press the button.', $faq->answer);
        $this->assertSame(['all'], $faq->audience);
        $this->assertFalse($faq->is_public);
        $this->assertTrue($faq->is_published);
        $this->assertSame(10, $faq->sort_order);
        $this->assertSame($admin->id, $faq->created_by);
        $this->assertSame($admin->id, $faq->updated_by);
    }

    public function test_a_new_entry_goes_to_the_end_of_its_topic_unless_an_order_is_given(): void
    {
        $this->faq('First in the topic', ['sort_order' => 10]);
        $this->faq('Second in the topic', ['sort_order' => 40]);

        $this->actingAs($this->admin());
        $this->post(route('admin.faq.store'), $this->payload(['question' => 'Third in the topic']));
        $this->post(route('admin.faq.store'), $this->payload(['question' => 'Placed at seven', 'sort_order' => '7']));

        $this->assertSame(50, Faq::where('question', 'Third in the topic')->value('sort_order'));
        $this->assertSame(7, Faq::where('question', 'Placed at seven')->value('sort_order'));
    }

    public function test_the_new_topic_text_is_used_instead_of_the_select(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Getting started', 'new_topic' => '  Brand new topic  ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Brand new topic', Faq::firstOrFail()->topic);
    }

    public function test_an_entry_can_be_made_for_chosen_roles_only(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['everyone' => null, 'audience' => ['custodian', 'maintenance_admin']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['custodian', 'maintenance_admin'], Faq::firstOrFail()->audience);
    }

    public function test_everyone_wins_over_ticked_roles(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['everyone' => '1', 'audience' => ['custodian']]));

        $this->assertSame(['all'], Faq::firstOrFail()->audience);
    }

    public function test_an_entry_can_be_saved_hidden(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['is_published' => null]));

        $this->assertFalse(Faq::firstOrFail()->is_published);
    }

    // ---------------------------------------------------------------- update

    public function test_the_admin_can_update_an_entry(): void
    {
        $admin = $this->admin();
        $faq = $this->faq('Old question here', ['sort_order' => 10]);

        $response = $this->actingAs($admin)->put(route('admin.faq.update', $faq), $this->payload([
            'topic' => 'Other topic',
            'question' => 'New question here',
            'answer' => 'A new answer text.',
            'everyone' => null,
            'audience' => ['finance'],
            'sort_order' => '5',
        ]));

        $response->assertRedirect(route('admin.faq.index', ['topic' => 'Other topic']));

        $faq->refresh();
        $this->assertSame('Other topic', $faq->topic);
        $this->assertSame('New question here', $faq->question);
        $this->assertSame('A new answer text.', $faq->answer);
        $this->assertSame(['finance'], $faq->audience);
        $this->assertSame(5, $faq->sort_order);
        $this->assertSame($admin->id, $faq->updated_by);
    }

    public function test_an_empty_sort_order_keeps_the_order_in_the_same_topic(): void
    {
        $faq = $this->faq('Keep my place', ['sort_order' => 30]);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Keep my place',
            'answer' => 'Edited answer text.',
            'sort_order' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(30, $faq->fresh()->sort_order);
        $this->assertSame('Edited answer text.', $faq->fresh()->answer);
    }

    public function test_moving_to_another_topic_without_an_order_goes_to_the_end_of_it(): void
    {
        $this->faq('Already there', ['topic' => 'Other topic', 'sort_order' => 40]);
        $faq = $this->faq('Moving over', ['topic' => 'Getting started', 'sort_order' => 10]);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'topic' => 'Other topic',
            'question' => 'Moving over',
            'sort_order' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Other topic', $faq->fresh()->topic);
        $this->assertSame(50, $faq->fresh()->sort_order);
    }

    public function test_the_edit_form_shows_the_saved_values(): void
    {
        $everyone = $this->faq('Entry for everyone', ['is_public' => true]);
        $custodian = $this->faq('Entry for the custodian', ['audience' => ['custodian']]);

        $this->actingAs($this->admin());

        $html = $this->get(route('admin.faq.edit', $everyone))->assertOk()->getContent();
        $this->assertStringContainsString('value="Entry for everyone"', $html);
        $this->assertStringContainsString('id="everyone" value="1" checked', $html);
        $this->assertStringContainsString('id="is_public" value="1" checked', $html);

        $html = $this->get(route('admin.faq.edit', $custodian))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="everyone" value="1" checked', $html);
        $this->assertStringContainsString('value="custodian" class="role-box" checked', $html);
    }

    public function test_the_create_form_has_the_formatting_hint_and_the_preview(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.faq.create'))->assertOk()
            ->assertSee('for bullets')
            ->assertSee('for steps')
            ->assertSee('**bold**', false)
            ->assertSee('Show before sign-in')
            ->assertSee('id="faq-preview"', false)
            ->assertSee('name="new_topic"', false);
    }

    public function test_a_failed_save_comes_back_with_what_was_typed(): void
    {
        $faq = $this->faq('Entry that will not save');

        $response = $this->actingAs($this->admin())->followingRedirects()
            ->from(route('admin.faq.edit', $faq))
            ->put(route('admin.faq.update', $faq), $this->payload(['question' => 'abc', 'new_topic' => 'Typed topic text']));

        $response->assertOk()
            ->assertSee('Typed topic text')
            ->assertSee('must be at least 5 characters');

        $this->assertSame('Entry that will not save', $faq->fresh()->question);
    }

    // ---------------------------------------------------------------- publish / hide

    public function test_the_admin_can_hide_and_publish_an_entry(): void
    {
        $admin = $this->admin();
        $faq = $this->faq('Toggle me please');
        $list = route('admin.faq.index');

        $this->actingAs($admin)->from($list)->patch(route('admin.faq.toggle', $faq))->assertRedirect($list);
        $this->assertFalse($faq->fresh()->is_published);
        $this->assertSame($admin->id, $faq->fresh()->updated_by);

        $this->actingAs($admin)->from($list)->patch(route('admin.faq.toggle', $faq))->assertRedirect($list);
        $this->assertTrue($faq->fresh()->is_published);
    }

    // ---------------------------------------------------------------- delete

    public function test_the_admin_can_delete_an_entry(): void
    {
        $faq = $this->faq('Delete me please');
        $keep = $this->faq('Keep me please');

        $this->actingAs($this->admin())->from(route('admin.faq.index'))
            ->delete(route('admin.faq.destroy', $faq))
            ->assertRedirect(route('admin.faq.index'))
            ->assertSessionHas('status');

        $this->assertModelMissing($faq);
        $this->assertModelExists($keep);
    }

    // ---------------------------------------------------------------- duplicate

    public function test_duplicate_makes_a_hidden_copy_at_the_end_of_the_topic(): void
    {
        $admin = $this->admin();
        $faq = $this->faq('Copy this question', ['audience' => ['custodian'], 'answer' => 'The same answer.', 'sort_order' => 10]);
        $this->faq('Last in topic', ['sort_order' => 70]);

        $response = $this->actingAs($admin)->post(route('admin.faq.duplicate', $faq));

        $copy = Faq::where('question', 'Copy this question (copy)')->firstOrFail();
        $response->assertRedirect(route('admin.faq.edit', $copy));

        $this->assertSame('Getting started', $copy->topic);
        $this->assertSame(['custodian'], $copy->audience);
        $this->assertSame('The same answer.', $copy->answer);
        $this->assertFalse($copy->is_published);
        $this->assertSame(80, $copy->sort_order);
        $this->assertSame($admin->id, $copy->created_by);

        // the original is untouched
        $this->assertTrue($faq->fresh()->is_published);
        $this->assertSame(10, $faq->fresh()->sort_order);
    }

    public function test_duplicating_twice_numbers_the_copies(): void
    {
        $faq = $this->faq('Copy this question');

        $this->actingAs($this->admin());
        $this->post(route('admin.faq.duplicate', $faq));
        $this->post(route('admin.faq.duplicate', $faq));

        $this->assertSame(1, Faq::where('question', 'Copy this question (copy)')->count());
        $this->assertSame(1, Faq::where('question', 'Copy this question (copy 2)')->count());
    }

    public function test_a_copy_of_a_very_long_question_still_fits(): void
    {
        $faq = $this->faq(str_repeat('a', 255));

        $this->actingAs($this->admin())->post(route('admin.faq.duplicate', $faq));

        $copy = Faq::where('id', '!=', $faq->id)->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strlen($copy->question));
        $this->assertStringEndsWith(' (copy)', $copy->question);
    }

    public function test_a_copy_keeps_before_sign_in_only_for_everyone_and_starts_hidden(): void
    {
        $faq = $this->faq('Public entry here', ['is_public' => true]);

        $this->actingAs($this->admin())->post(route('admin.faq.duplicate', $faq));

        $copy = Faq::where('id', '!=', $faq->id)->firstOrFail();
        $this->assertTrue($copy->is_public);
        $this->assertFalse($copy->is_published);

        // so a visitor does not see the copy until it is published
        $this->assertSame(['Public entry here'], $this->helpFor(null));
    }

    // ---------------------------------------------------------------- reorder

    public function test_move_up_and_down_swaps_places_inside_the_topic(): void
    {
        $a = $this->faq('Entry A', ['topic' => 'Order', 'sort_order' => 10]);
        $b = $this->faq('Entry B', ['topic' => 'Order', 'sort_order' => 20]);
        $c = $this->faq('Entry C', ['topic' => 'Order', 'sort_order' => 30]);
        $other = $this->faq('Other topic entry', ['topic' => 'Elsewhere', 'sort_order' => 10]);

        $this->actingAs($this->admin());

        $this->post(route('admin.faq.move', $b), ['direction' => 'up']);
        $this->assertSame(['Entry B', 'Entry A', 'Entry C'], $this->order('Order'));

        $this->post(route('admin.faq.move', $b), ['direction' => 'down']);
        $this->assertSame(['Entry A', 'Entry B', 'Entry C'], $this->order('Order'));

        $this->assertSame(10, $other->fresh()->sort_order);   // another topic is never touched
    }

    public function test_moving_past_the_ends_changes_nothing(): void
    {
        $a = $this->faq('Entry A', ['topic' => 'Order', 'sort_order' => 10]);
        $this->faq('Entry B', ['topic' => 'Order', 'sort_order' => 20]);
        $c = $this->faq('Entry C', ['topic' => 'Order', 'sort_order' => 30]);

        $this->actingAs($this->admin());

        $this->post(route('admin.faq.move', $a), ['direction' => 'up']);
        $this->post(route('admin.faq.move', $c), ['direction' => 'down']);

        $this->assertSame(['Entry A', 'Entry B', 'Entry C'], $this->order('Order'));
        $this->assertSame(0, $this->rows(AuditLogger::FAQ_REORDERED)->count());
    }

    public function test_equal_sort_orders_cannot_get_stuck(): void
    {
        $this->faq('Entry A', ['topic' => 'Order', 'sort_order' => 0]);
        $this->faq('Entry B', ['topic' => 'Order', 'sort_order' => 0]);
        $c = $this->faq('Entry C', ['topic' => 'Order', 'sort_order' => 0]);

        $this->actingAs($this->admin())->post(route('admin.faq.move', $c), ['direction' => 'up']);

        $this->assertSame(['Entry A', 'Entry C', 'Entry B'], $this->order('Order'));
        $this->assertSame([10, 20, 30], Faq::where('topic', 'Order')->ordered()->pluck('sort_order')->all());
    }

    public function test_a_bad_direction_is_refused(): void
    {
        $faq = $this->faq('Entry A');

        $this->actingAs($this->admin())->post(route('admin.faq.move', $faq), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');
    }

    // ---------------------------------------------------------------- validation

    public function test_validation_errors(): void
    {
        $cases = [
            'question too short' => [['question' => 'abcd'], 'question'],
            'question only spaces around' => [['question' => '  abcd  '], 'question'],
            'question too long' => [['question' => str_repeat('q', 256)], 'question'],
            'question missing' => [['question' => ''], 'question'],
            'answer too short' => [['answer' => 'abcd'], 'answer'],
            'answer too long' => [['answer' => str_repeat('a', 5001)], 'answer'],
            'answer missing' => [['answer' => ''], 'answer'],
            'topic missing' => [['topic' => ''], 'topic'],
            'topic too long' => [['topic' => str_repeat('t', 61)], 'topic'],
            'unknown role' => [['everyone' => null, 'audience' => ['hacker']], 'audience.0'],
            'no audience at all' => [['everyone' => null], 'audience'],
            'sort order not a number' => [['sort_order' => 'abc'], 'sort_order'],
            'sort order negative' => [['sort_order' => '-1'], 'sort_order'],
        ];

        $this->actingAs($this->admin());

        foreach ($cases as [$over, $errorKey]) {
            $this->post(route('admin.faq.store'), $this->payload($over))
                ->assertSessionHasErrors($errorKey);
        }

        $this->assertSame(0, Faq::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'faq_%')->count());
    }

    public function test_the_length_limits_accept_the_edges(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['question' => 'abcde', 'answer' => 'abcde']))->assertSessionHasNoErrors();
        $this->post(route('admin.faq.store'), $this->payload(['question' => str_repeat('q', 255), 'answer' => str_repeat('a', 5000)]))->assertSessionHasNoErrors();

        $this->assertSame(2, Faq::count());
    }

    public function test_a_duplicate_question_is_refused_inside_the_same_topic_only(): void
    {
        $this->faq('Same question here', ['topic' => 'Topic A']);

        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Topic A', 'question' => 'Same question here']))
            ->assertSessionHasErrors('question');
        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Topic A', 'question' => '  Same question here  ']))
            ->assertSessionHasErrors('question');
        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Getting started', 'new_topic' => 'Topic A', 'question' => 'Same question here']))
            ->assertSessionHasErrors('question');
        $this->assertSame(1, Faq::count());

        // another topic may use the same words
        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Topic B', 'question' => 'Same question here']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Faq::count());
    }

    public function test_a_duplicate_is_refused_on_update_but_the_entry_may_keep_its_own_question(): void
    {
        $this->faq('Question one here', ['topic' => 'Topic A', 'sort_order' => 10]);
        $two = $this->faq('Question two here', ['topic' => 'Topic A', 'sort_order' => 20]);

        $this->actingAs($this->admin());

        // its own question: fine
        $this->put(route('admin.faq.update', $two), $this->payload(['topic' => 'Topic A', 'question' => 'Question two here', 'sort_order' => '20']))
            ->assertSessionHasNoErrors();

        // the question of a neighbour in the same topic: refused
        $this->put(route('admin.faq.update', $two), $this->payload(['topic' => 'Topic A', 'question' => 'Question one here']))
            ->assertSessionHasErrors('question');
        $this->assertSame('Question two here', $two->fresh()->question);

        // the same words in another topic: fine
        $this->put(route('admin.faq.update', $two), $this->payload(['topic' => 'Topic B', 'question' => 'Question one here']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Topic B', $two->fresh()->topic);
    }

    public function test_every_role_slug_is_accepted_as_an_audience(): void
    {
        $this->actingAs($this->admin());

        foreach (Faq::roleSlugs() as $i => $slug) {
            $this->post(route('admin.faq.store'), $this->payload(['everyone' => null, 'audience' => [$slug], 'question' => "Entry for role number {$i}"]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(count(Faq::roleSlugs()), Faq::count());
    }

    // ---------------------------------------------------------------- before sign-in (is_public)

    public function test_before_sign_in_is_refused_for_a_role_audience(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['everyone' => null, 'audience' => ['custodian'], 'is_public' => '1']))
            ->assertSessionHasErrors('is_public');

        $this->assertSame(0, Faq::count());
    }

    public function test_before_sign_in_is_saved_for_everyone(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['is_public' => '1']))->assertSessionHasNoErrors();

        $faq = Faq::firstOrFail();
        $this->assertTrue($faq->is_public);
        $this->assertSame(['all'], $faq->audience);
    }

    public function test_before_sign_in_cannot_be_added_to_a_role_entry_on_update(): void
    {
        $faq = $this->faq('Entry for the custodian', ['audience' => ['custodian']]);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Entry for the custodian',
            'everyone' => null,
            'audience' => ['custodian'],
            'is_public' => '1',
        ]))->assertSessionHasErrors('is_public');

        $this->assertFalse($faq->fresh()->is_public);
    }

    public function test_narrowing_the_audience_never_leaves_a_public_entry(): void
    {
        $faq = $this->faq('Was public before', ['is_public' => true]);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Was public before',
            'everyone' => null,
            'audience' => ['custodian'],
            'is_public' => null,
        ]))->assertSessionHasNoErrors();

        $faq->refresh();
        $this->assertSame(['custodian'], $faq->audience);
        $this->assertFalse($faq->is_public);
        $this->assertSame([], $this->helpFor(null));   // a visitor sees nothing
    }

    // ---------------------------------------------------------------- audit log

    public function test_every_action_is_written_to_the_audit_log_with_the_question(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // create
        $this->post(route('admin.faq.store'), $this->payload(['topic' => 'Audit topic', 'question' => 'Audit question one']));
        $faq = Faq::firstOrFail();
        $created = $this->rows(AuditLogger::FAQ_CREATED);
        $this->assertCount(1, $created);
        $this->assertSame('FAQ added: Audit question one (topic: Audit topic)', $created->first()->description);
        $this->assertSame($admin->id, $created->first()->user_id);

        // update
        $this->put(route('admin.faq.update', $faq), $this->payload([
            'topic' => 'Audit topic', 'question' => 'Audit question one', 'answer' => 'A different answer.', 'sort_order' => '10',
        ]));
        $updated = $this->rows(AuditLogger::FAQ_UPDATED);
        $this->assertCount(1, $updated);
        $this->assertStringContainsString('FAQ updated: Audit question one', $updated->first()->description);
        $this->assertStringContainsString('answer', $updated->first()->description);

        // hide, publish
        $this->patch(route('admin.faq.toggle', $faq));
        $this->assertStringContainsString('FAQ hidden: Audit question one', $this->rows(AuditLogger::FAQ_HIDDEN)->first()->description);
        $this->patch(route('admin.faq.toggle', $faq));
        $this->assertStringContainsString('FAQ published: Audit question one', $this->rows(AuditLogger::FAQ_PUBLISHED)->first()->description);

        // reorder (needs a neighbour)
        $second = $this->faq('Audit question two', ['topic' => 'Audit topic', 'sort_order' => 20]);
        $this->post(route('admin.faq.move', $second), ['direction' => 'up']);
        $this->assertSame('FAQ reordered: Audit question two moved up (topic: Audit topic)', $this->rows(AuditLogger::FAQ_REORDERED)->first()->description);

        // duplicate
        $this->post(route('admin.faq.duplicate', $faq));
        $this->assertSame(1, $this->rows(AuditLogger::FAQ_CREATED)->filter(
            fn (AuditLog $row) => str_contains($row->description, 'FAQ duplicated: Audit question one (copy)')
        )->count());

        // delete
        $this->delete(route('admin.faq.destroy', $faq));
        $deleted = $this->rows(AuditLogger::FAQ_DELETED);
        $this->assertCount(1, $deleted);
        $this->assertSame('FAQ deleted: Audit question one (topic: Audit topic)', $deleted->first()->description);
        $this->assertSame($admin->id, $deleted->first()->user_id);
    }

    public function test_saving_without_changes_writes_no_update_row(): void
    {
        $faq = $this->faq('Nothing will change', ['sort_order' => 10]);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'topic' => $faq->topic, 'question' => $faq->question, 'answer' => $faq->answer, 'sort_order' => '10',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->rows(AuditLogger::FAQ_UPDATED)->count());
    }

    public function test_hiding_through_the_form_logs_the_update_and_the_hide(): void
    {
        $faq = $this->faq('Hidden through the form');

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Hidden through the form', 'answer' => $faq->answer, 'sort_order' => '10', 'is_published' => null,
        ]));

        $this->assertFalse($faq->fresh()->is_published);
        $this->assertSame(1, $this->rows(AuditLogger::FAQ_UPDATED)->count());
        $this->assertSame(1, $this->rows(AuditLogger::FAQ_HIDDEN)->count());
    }

    public function test_the_action_names_are_registered(): void
    {
        foreach ([
            AuditLogger::FAQ_CREATED, AuditLogger::FAQ_UPDATED, AuditLogger::FAQ_PUBLISHED,
            AuditLogger::FAQ_HIDDEN, AuditLogger::FAQ_REORDERED, AuditLogger::FAQ_DELETED,
        ] as $action) {
            $this->assertContains($action, AuditLogger::actions());
            $this->assertLessThanOrEqual(50, strlen($action));
        }
    }

    public function test_the_audit_log_page_shows_the_faq_rows_properly(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.faq.store'), $this->payload(['question' => 'Shown in the audit log']));
        $faq = Faq::firstOrFail();
        $this->patch(route('admin.faq.toggle', $faq));
        $this->delete(route('admin.faq.destroy', $faq));

        $this->get(route('admin.audit-logs.index'))->assertOk()
            ->assertSee('FAQ Created')
            ->assertSee('FAQ Hidden')
            ->assertSee('FAQ Deleted')
            ->assertDontSee('Faq Created')
            ->assertSee('FAQ added: Shown in the audit log')
            ->assertSee('FAQ deleted: Shown in the audit log');
    }

    // ---------------------------------------------------------------- the list page

    private function listFixtures(): array
    {
        return [
            'a' => $this->faq('Alpha public question', ['topic' => 'Alpha', 'is_public' => true, 'sort_order' => 10, 'answer' => 'Plain answer one.']),
            'b' => $this->faq('Alpha hidden custodian question', ['topic' => 'Alpha', 'audience' => ['custodian'], 'is_published' => false, 'sort_order' => 20, 'answer' => 'Plain answer two.']),
            'c' => $this->faq('Beta finance question', ['topic' => 'Beta', 'audience' => ['finance', 'president'], 'sort_order' => 10, 'answer' => 'The Zebra procedure.']),
        ];
    }

    /** @return list<string> */
    private function listed(array $query = []): array
    {
        return $this->get(route('admin.faq.index', $query))->assertOk()
            ->viewData('faqs')->getCollection()->pluck('question')->all();
    }

    public function test_the_list_shows_the_entries_the_count_and_the_badges(): void
    {
        $this->listFixtures();

        $html = $this->actingAs($this->admin())->get(route('admin.faq.index'))->assertOk()->getContent();

        foreach (['Alpha public question', 'Alpha hidden custodian question', 'Beta finance question'] as $question) {
            $this->assertStringContainsString($question, $html);
        }

        $this->assertStringContainsString('<b>3</b> entries', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-circle-check"></i> Published', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-eye-slash"></i> Hidden', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-globe"></i> Public', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-users"></i> Everyone', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-user"></i> Custodian', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-user"></i> Finance', $html);
        $this->assertStringContainsString('<i class="fa-solid fa-user"></i> President', $html);
    }

    public function test_the_list_has_the_row_actions(): void
    {
        $faqs = $this->listFixtures();

        $html = $this->actingAs($this->admin())->get(route('admin.faq.index'))->getContent();

        $this->assertStringContainsString(route('admin.faq.create'), $html);
        $this->assertStringContainsString(route('admin.faq.edit', $faqs['a']), $html);
        $this->assertStringContainsString(route('admin.faq.toggle', $faqs['a']), $html);
        $this->assertStringContainsString(route('admin.faq.move', $faqs['a']), $html);
        $this->assertStringContainsString(route('admin.faq.duplicate', $faqs['a']), $html);
        $this->assertStringContainsString(route('admin.faq.destroy', $faqs['a']), $html);
        $this->assertStringContainsString('confirm(', $html);   // delete asks first
    }

    public function test_the_arrows_know_the_first_and_last_entry_of_each_topic(): void
    {
        $faqs = $this->listFixtures();

        $edges = $this->actingAs($this->admin())->get(route('admin.faq.index'))->viewData('edges');

        $this->assertSame(['first' => $faqs['a']->id, 'last' => $faqs['b']->id], $edges['Alpha']);
        $this->assertSame(['first' => $faqs['c']->id, 'last' => $faqs['c']->id], $edges['Beta']);
    }

    public function test_the_list_filters_by_topic_and_status(): void
    {
        $this->listFixtures();
        $this->actingAs($this->admin());

        $this->assertSame(['Alpha public question', 'Alpha hidden custodian question', 'Beta finance question'], $this->listed());
        $this->assertSame(['Beta finance question'], $this->listed(['topic' => 'Beta']));
        $this->assertSame(['Alpha hidden custodian question'], $this->listed(['status' => 'hidden']));
        $this->assertSame(['Alpha public question', 'Beta finance question'], $this->listed(['status' => 'published']));
        $this->assertSame([], $this->listed(['topic' => 'Beta', 'status' => 'hidden']));
    }

    public function test_the_list_filters_public_entries(): void
    {
        $this->listFixtures();
        $this->actingAs($this->admin());

        $this->assertSame(['Alpha public question'], $this->listed(['public' => 'yes']));
    }

    public function test_the_list_search_reads_questions_and_answers_without_case(): void
    {
        $this->listFixtures();
        $this->actingAs($this->admin());

        $this->assertSame(['Beta finance question'], $this->listed(['q' => 'finance']));      // in the question
        $this->assertSame(['Beta finance question'], $this->listed(['q' => 'zebra']));        // in the answer
        $this->assertSame(['Beta finance question'], $this->listed(['q' => 'ZEBRA']));        // any case
        $this->assertSame(['Alpha public question', 'Alpha hidden custodian question'], $this->listed(['q' => 'alpha']));
    }

    public function test_a_percent_sign_in_the_search_is_taken_literally(): void
    {
        $this->listFixtures();
        $this->actingAs($this->admin());

        $this->assertSame([], $this->listed(['q' => '%']));
        $this->assertSame([], $this->listed(['q' => '_']));
    }

    public function test_the_count_and_the_empty_state_follow_the_filters(): void
    {
        $this->listFixtures();
        $this->actingAs($this->admin());

        $this->get(route('admin.faq.index', ['topic' => 'Beta']))
            ->assertSee('<b>1</b> entry match these filters', false);

        $this->get(route('admin.faq.index', ['q' => 'nothing matches this']))
            ->assertSee('<b>0</b> entries match these filters', false)
            ->assertSee('No entries match these filters.');
    }

    public function test_an_empty_manager_says_so(): void
    {
        $this->actingAs($this->admin())->get(route('admin.faq.index'))->assertOk()->assertSee('No FAQ entries yet');
    }

    public function test_odd_filter_values_are_refused(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.faq.index', ['status' => 'nonsense']))->assertSessionHasErrors('status');
        $this->get(route('admin.faq.index', ['public' => 'maybe']))->assertSessionHasErrors('public');
        $this->get(route('admin.faq.index', ['q' => str_repeat('x', 101)]))->assertSessionHasErrors('q');
    }

    public function test_the_list_holds_25_rows_and_keeps_the_filters_on_the_next_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->faq(sprintf('Page entry %02d', $i), ['topic' => 'Bigtopic', 'sort_order' => $i]);
        }

        $this->actingAs($this->admin());

        $first = $this->get(route('admin.faq.index', ['topic' => 'Bigtopic']))->assertOk();
        $this->assertCount(25, $first->viewData('faqs')->items());
        $this->assertSame(30, $first->viewData('total'));
        $first->assertSee('Page entry 25')->assertDontSee('Page entry 26');
        $this->assertStringContainsString('topic=Bigtopic', $first->getContent());   // the page links keep the filter

        $second = $this->get(route('admin.faq.index', ['topic' => 'Bigtopic', 'page' => 2]))->assertOk();
        $this->assertCount(5, $second->viewData('faqs')->items());
        $second->assertSee('Page entry 26')->assertSee('Page entry 30')->assertDontSee('Page entry 25');
    }

    // ---------------------------------------------------------------- menu and Help page button

    public function test_the_menu_item_and_the_help_button_show_only_with_manage_faq(): void
    {
        $url = route('admin.faq.index');

        $this->actingAs($this->admin())->get(route('help.index'))->assertOk()
            ->assertSee('FAQ manager')
            ->assertSee('Manage FAQ')
            ->assertSee('fa-pen-to-square', false)
            ->assertSee($url, false);

        foreach (self::OTHER_ROLES as $who) {
            $this->actingAs($this->user($who))->get(route('help.index'))->assertOk()
                ->assertDontSee('FAQ manager')
                ->assertDontSee('Manage FAQ')
                ->assertDontSee($url, false);
        }
    }

    public function test_the_menu_item_is_highlighted_on_the_manager(): void
    {
        $this->actingAs($this->admin())->get(route('admin.faq.index'))->assertOk()
            ->assertSee('<a href="'.route('admin.faq.index').'" class="active">', false);
    }

    public function test_a_visitor_never_sees_the_manage_button(): void
    {
        $this->get(route('help.public'))->assertOk()
            ->assertDontSee('Manage FAQ')
            ->assertDontSee('FAQ manager');
    }

    // ---------------------------------------------------------------- changes reach /help at once

    public function test_an_entry_for_the_custodian_only_is_seen_only_by_the_custodian(): void
    {
        $this->actingAs($this->admin())->post(route('admin.faq.store'), $this->payload([
            'topic' => 'Custodian topic',
            'question' => 'Custodian only question',
            'everyone' => null,
            'audience' => ['custodian'],
        ]))->assertSessionHasNoErrors();

        $this->assertContains('Custodian only question', $this->helpFor('custodian'));

        foreach (['requester', 'finance', 'president', 'depthead', 'treasurer', 'maintenance'] as $who) {
            $this->assertNotContains('Custodian only question', $this->helpFor($who), $who);
        }
        $this->assertNotContains('Custodian only question', $this->helpFor(null), 'visitor');
    }

    public function test_hiding_an_entry_removes_it_from_help_at_once_and_publishing_brings_it_back(): void
    {
        $faq = $this->faq('Visible then hidden');

        $this->assertContains('Visible then hidden', $this->helpFor('requester'));

        $this->actingAs($this->admin())->patch(route('admin.faq.toggle', $faq));
        foreach (['requester', 'custodian', 'maintenance'] as $who) {
            $this->assertNotContains('Visible then hidden', $this->helpFor($who), $who);
        }

        $this->actingAs($this->admin())->patch(route('admin.faq.toggle', $faq));
        $this->assertContains('Visible then hidden', $this->helpFor('requester'));
    }

    public function test_changing_the_audience_moves_the_entry_between_roles(): void
    {
        $faq = $this->faq('Audience moves', ['audience' => ['custodian']]);

        $this->assertContains('Audience moves', $this->helpFor('custodian'));
        $this->assertNotContains('Audience moves', $this->helpFor('requester'));

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Audience moves', 'everyone' => null, 'audience' => ['requester'], 'sort_order' => '10',
        ]))->assertSessionHasNoErrors();

        $this->assertNotContains('Audience moves', $this->helpFor('custodian'));
        $this->assertContains('Audience moves', $this->helpFor('requester'));
    }

    public function test_editing_the_text_shows_on_help_at_once(): void
    {
        $faq = $this->faq('Text that changes', ['answer' => 'The old answer.']);

        $this->actingAs($this->admin())->put(route('admin.faq.update', $faq), $this->payload([
            'question' => 'Text that changes', 'answer' => "- one bullet\n- two bullets", 'sort_order' => '10',
        ]));

        $this->actingAs($this->user('requester'))->get(route('help.index'))->assertOk()->assertSee('Text that changes');
        $this->assertSame("- one bullet\n- two bullets", $faq->fresh()->answer);
    }

    public function test_deleting_an_entry_removes_it_from_help(): void
    {
        $faq = $this->faq('Soon gone');

        $this->assertContains('Soon gone', $this->helpFor('requester'));

        $this->actingAs($this->admin())->delete(route('admin.faq.destroy', $faq));

        $this->assertNotContains('Soon gone', $this->helpFor('requester'));
    }

    public function test_the_order_set_in_the_manager_is_the_order_on_help(): void
    {
        $a = $this->faq('Entry A', ['topic' => 'Order', 'sort_order' => 10]);
        $b = $this->faq('Entry B', ['topic' => 'Order', 'sort_order' => 20]);

        $this->assertSame(['Entry A', 'Entry B'], $this->helpFor('requester'));

        $this->actingAs($this->admin())->post(route('admin.faq.move', $b), ['direction' => 'up']);

        $this->assertSame(['Entry B', 'Entry A'], $this->helpFor('requester'));
    }

    public function test_a_public_entry_reaches_visitors_and_leaves_them_when_hidden_or_made_private(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.faq.store'), $this->payload([
            'topic' => 'Public topic', 'question' => 'Visible before sign in', 'is_public' => '1',
        ]))->assertSessionHasNoErrors();
        $faq = Faq::firstOrFail();

        $this->assertContains('Visible before sign in', $this->helpFor(null));
        $this->assertContains('Visible before sign in', $this->helpFor('requester'));

        // hidden: gone for everybody
        $this->actingAs($admin)->patch(route('admin.faq.toggle', $faq));
        $this->assertNotContains('Visible before sign in', $this->helpFor(null));
        $this->assertNotContains('Visible before sign in', $this->helpFor('requester'));

        // published again, but no longer public: signed-in people only
        $this->actingAs($admin)->patch(route('admin.faq.toggle', $faq));
        $this->actingAs($admin)->put(route('admin.faq.update', $faq), $this->payload([
            'topic' => 'Public topic', 'question' => 'Visible before sign in', 'is_public' => null, 'sort_order' => '10',
        ]))->assertSessionHasNoErrors();

        $this->assertNotContains('Visible before sign in', $this->helpFor(null));
        $this->assertContains('Visible before sign in', $this->helpFor('requester'));
    }
}
