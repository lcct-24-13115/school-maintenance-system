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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Help & FAQ Phase 2: the page, who sees which entry, search, topics. (Menu and auth-page links are added in 2D.) */
class FaqPageTest extends TestCase
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

    private function entries(): void
    {
        $this->faq('A everyone', ['sort_order' => 10]);
        $this->faq('B custodian only', ['audience' => ['custodian'], 'sort_order' => 20]);
        $this->faq('C admin and president', ['audience' => ['maintenance_admin', 'president'], 'sort_order' => 30]);
        $this->faq('D hidden', ['is_published' => false, 'sort_order' => 40]);
        $this->faq('E public', ['is_public' => true, 'sort_order' => 50]);
    }

    /** @return list<string> */
    private function questions(TestResponse $response): array
    {
        return $response->viewData('entries')->pluck('question')->all();
    }

    private function as(string $who, string $url = '/help'): TestResponse
    {
        return $this->actingAs($this->user($who))->get($url);
    }

    public function test_a_guest_on_help_is_sent_to_login(): void
    {
        $this->get('/help')->assertRedirect(route('login'));
    }

    public function test_the_routes_have_their_names(): void
    {
        $this->assertSame('/help', route('help.index', [], false));
        $this->assertSame('/help-public', route('help.public', [], false));
    }

    public function test_a_guest_sees_only_public_entries_for_everyone(): void
    {
        $this->entries();
        $this->faq('F public but for custodians', ['audience' => ['custodian'], 'is_public' => true, 'sort_order' => 60]);
        $this->faq('G public but hidden', ['is_public' => true, 'is_published' => false, 'sort_order' => 70]);

        $response = $this->get('/help-public')->assertOk();

        $this->assertSame(['E public'], $this->questions($response));
        $response->assertSee('E public')->assertDontSee('A everyone')->assertDontSee('custodians')->assertDontSee('G public');
    }

    public function test_each_role_sees_only_its_entries(): void
    {
        $this->entries();

        $this->assertSame(['A everyone', 'B custodian only', 'E public'], $this->questions($this->as('custodian')));
        $this->assertSame(['A everyone', 'C admin and president', 'E public'], $this->questions($this->as('maintenance')));
        $this->assertSame(['A everyone', 'C admin and president', 'E public'], $this->questions($this->as('president')));

        foreach (['finance', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->assertSame(['A everyone', 'E public'], $this->questions($this->as($who)), $who);
        }
    }

    public function test_the_page_never_shows_another_roles_entry_or_an_unpublished_one(): void
    {
        $this->entries();

        $this->as('requester')->assertOk()
            ->assertSee('A everyone')
            ->assertDontSee('B custodian only')
            ->assertDontSee('C admin and president')
            ->assertDontSee('D hidden');
    }

    public function test_a_signed_in_person_on_the_public_page_is_sent_to_the_real_one(): void
    {
        $this->as('requester', '/help-public?q=reset&topic=Billing')
            ->assertRedirect(route('help.index', ['q' => 'reset', 'topic' => 'Billing']));
    }

    public function test_search_finds_by_question_and_by_answer(): void
    {
        $this->faq('How do I reset my password?', ['answer' => 'Use the link.', 'sort_order' => 10]);
        $this->faq('Where are my photos?', ['answer' => 'They are on the ticket page.', 'sort_order' => 20]);

        $this->assertSame(['How do I reset my password?'], $this->questions($this->as('requester', '/help?q=password')));
        $this->assertSame(['Where are my photos?'], $this->questions($this->as('requester', '/help?q=ticket+page')));
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->faq('How do I Reset my PASSWORD?', ['answer' => 'Use the LINK.']);

        $this->assertCount(1, $this->questions($this->as('requester', '/help?q=reset+my+password')));
        $this->assertCount(1, $this->questions($this->as('requester', '/help?q=RESET')));
        $this->assertCount(1, $this->questions($this->as('requester', '/help?q=use+the+link')));
    }

    public function test_search_never_reaches_entries_the_person_may_not_read(): void
    {
        $this->faq('Secret custodian stuff', ['audience' => ['custodian']]);
        $this->faq('Secret hidden stuff', ['is_published' => false]);

        $this->assertSame([], $this->questions($this->as('requester', '/help?q=secret')));
        $this->assertSame(['Secret custodian stuff'], $this->questions($this->as('custodian', '/help?q=secret')));
    }

    public function test_an_empty_result_shows_the_empty_state(): void
    {
        $this->faq('Something');

        $response = $this->as('requester', '/help?q=zzzz');

        $this->assertSame([], $this->questions($response));
        $response->assertOk()->assertSee('No answers found');
    }

    public function test_the_search_text_is_escaped(): void
    {
        $this->faq('Something');

        $this->as('requester', '/help?q='.urlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_wildcards_in_the_search_are_taken_literally(): void
    {
        $this->faq('Plain question');
        $this->faq('Is it 100% free?');

        $this->assertSame(['Is it 100% free?'], $this->questions($this->as('requester', '/help?q='.urlencode('100%'))));
        $this->assertSame([], $this->questions($this->as('requester', '/help?q=_')));
        $this->assertSame(['Is it 100% free?'], $this->questions($this->as('requester', '/help?q=%25')));
    }

    public function test_topic_filter_and_counts(): void
    {
        $this->faq('Alpha one', ['topic' => 'Alpha', 'sort_order' => 10]);
        $this->faq('Alpha two', ['topic' => 'Alpha', 'sort_order' => 20, 'answer' => 'mentions foo']);
        $this->faq('Beta one', ['topic' => 'Beta', 'sort_order' => 30]);
        $this->faq('Beta foo', ['topic' => 'Beta', 'sort_order' => 40]);

        $all = $this->as('requester');
        $this->assertSame(4, $all->viewData('total'));
        $this->assertSame([['name' => 'Alpha', 'count' => 2], ['name' => 'Beta', 'count' => 2]], $all->viewData('topics')->all());

        $this->assertSame(['Beta one', 'Beta foo'], $this->questions($this->as('requester', '/help?topic=Beta')));
        $this->assertSame('Beta', $this->as('requester', '/help?topic=Beta')->viewData('topic'));

        // the counts follow the search, and the chips stay
        $searched = $this->as('requester', '/help?q=foo');
        $this->assertSame(2, $searched->viewData('total'));
        $this->assertSame([['name' => 'Alpha', 'count' => 1], ['name' => 'Beta', 'count' => 1]], $searched->viewData('topics')->all());

        $this->assertSame(['Beta foo'], $this->questions($this->as('requester', '/help?q=foo&topic=Beta')));
    }

    public function test_a_topic_the_person_cannot_see_is_ignored(): void
    {
        $this->faq('Public thing', ['topic' => 'Alpha']);
        $this->faq('Custodian thing', ['topic' => 'Custodian topic', 'audience' => ['custodian']]);

        $response = $this->as('requester', '/help?topic='.urlencode('Custodian topic'));

        $this->assertNull($response->viewData('topic'));
        $this->assertSame(['Public thing'], $this->questions($response));
        $this->assertSame(['Alpha'], $response->viewData('topics')->pluck('name')->all());
    }

    public function test_odd_values_in_the_address_do_not_break_the_page(): void
    {
        $this->faq('Something');

        $this->get('/help-public?q[]=a')->assertOk();
        $this->as('requester', '/help?q[]=a&topic[]=b')->assertOk();
    }

    public function test_the_search_is_trimmed_and_limited_to_100_characters(): void
    {
        $this->faq('Something');

        $long = str_repeat('a', 150);
        $this->assertSame(str_repeat('a', 100), $this->as('requester', '/help?q='.$long)->viewData('q'));
        $this->assertSame('reset my password', $this->as('requester', '/help?q='.urlencode("   reset   my \t password  "))->viewData('q'));
        $this->assertSame('', $this->as('requester', '/help?q='.urlencode('     '))->viewData('q'));
    }

    public function test_the_search_works_on_the_public_page_too(): void
    {
        $this->faq('How do I sign in?', ['is_public' => true]);
        $this->faq('How do I send a request?', ['is_public' => false]);

        $this->assertSame(['How do I sign in?'], $this->questions($this->get('/help-public?q=how+do+i')));
        $this->assertSame([], $this->questions($this->get('/help-public?q=send')));
    }

    // ---------- Phase 2B: the design ----------

    public function test_the_page_has_the_design_pieces(): void
    {
        $this->faq('Alpha question', ['topic' => 'Alpha']);
        $this->faq('Beta question', ['topic' => 'Beta', 'sort_order' => 20]);

        $this->as('requester')->assertOk()
            ->assertSee('Help &amp; FAQ', false)
            ->assertSee('name="q"', false)
            ->assertSee('maxlength="100"', false)
            ->assertSee('<details', false)
            ->assertSee('<summary>Alpha question</summary>', false)
            ->assertSeeInOrder(['All topics', 'Alpha', 'Beta'])
            ->assertSee('id="still-need-help"', false);
    }

    public function test_search_and_chips_are_plain_get_forms_and_links(): void
    {
        $this->faq('Alpha question', ['topic' => 'Alpha']);
        $this->faq('Beta question', ['topic' => 'Beta', 'sort_order' => 20]);

        $this->as('requester')->assertOk()
            ->assertSee('method="GET"', false)
            ->assertSee('action="'.route('help.index').'"', false)
            ->assertSee('href="'.route('help.index', ['topic' => 'Beta']).'"', false);

        $this->faq('Public one', ['is_public' => true, 'topic' => 'Gamma']);
        $this->app['auth']->forgetGuards();   // the person above is signed in; the next request is a visitor
        $this->get('/help-public')->assertOk()
            ->assertSee('action="'.route('help.public').'"', false)
            ->assertSee('href="'.route('help.public', ['topic' => 'Gamma']).'"', false);
    }

    public function test_the_layout_switches_between_guest_and_signed_in(): void
    {
        $this->faq('Public one', ['is_public' => true]);

        $this->get('/help-public')->assertOk()
            ->assertSee('auth-card', false)
            ->assertSee('Back to sign in');

        $this->as('requester')->assertOk()
            ->assertDontSee('auth-card', false)
            ->assertDontSee('Back to sign in');
    }

    public function test_the_answer_is_shown_through_the_safe_accessor(): void
    {
        $this->faq('Q', ['answer' => "**Bold** text <script>alert(1)</script>\n\n- one\n- two"]);

        $this->as('requester')->assertOk()
            ->assertSee('<strong>Bold</strong>', false)
            ->assertSee('<li>one</li>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_match_is_marked_in_the_question_only(): void
    {
        $this->faq('How do I Reset my password?', ['answer' => 'Use the link.']);
        $this->faq('Where are my photos?', ['answer' => 'Reset happens here.', 'sort_order' => 20]);

        $this->as('requester', '/help?q=reset')->assertOk()
            ->assertSee('How do I <mark>Reset</mark> my password?', false)
            ->assertSee('Where are my photos?')
            ->assertDontSee('<mark>Where', false);
    }

    public function test_the_mark_cannot_inject_html(): void
    {
        $this->faq('Fix <b>this</b> now', ['answer' => 'x']);

        $this->as('requester', '/help?q=this')->assertOk()
            ->assertSee('Fix &lt;b&gt;<mark>this</mark>&lt;/b&gt; now', false)
            ->assertDontSee('<b>this</b>', false);
    }

    public function test_special_characters_in_the_search_do_not_break_the_page(): void
    {
        $this->faq('Something (odd) / here [x] + y');

        foreach (['(', '/', '[x]', '+ y', '\\', '.*', '(odd)'] as $q) {
            $this->as('requester', '/help?q='.urlencode($q))->assertOk();
        }

        $this->as('requester', '/help?q='.urlencode('(odd)'))->assertSee('<mark>(odd)</mark>', false);
    }

    public function test_the_empty_state_has_both_links(): void
    {
        $this->faq('Something');

        $this->as('requester', '/help?q=zzzz')->assertOk()
            ->assertSee('No answers found for &ldquo;zzzz&rdquo;', false)
            ->assertSee('Clear the search')
            ->assertSee('href="'.route('help.index').'"', false)
            ->assertSee('Still need help?')
            ->assertSee('href="#still-need-help"', false);
    }

    public function test_the_empty_state_offers_all_topics_when_the_topic_has_no_match(): void
    {
        $this->faq('Alpha foo', ['topic' => 'Alpha']);
        $this->faq('Beta bar', ['topic' => 'Beta', 'sort_order' => 20]);

        $this->as('requester', '/help?q=foo&topic=Beta')->assertOk()
            ->assertSee('No answers found for &ldquo;foo&rdquo;', false)
            ->assertSee('Search all topics (1)');
    }

    public function test_a_few_matches_open_by_default_and_many_do_not(): void
    {
        foreach (range(1, 5) as $i) {
            $this->faq("Password question $i", ['sort_order' => $i]);
        }

        $this->as('requester', '/help?q=password')->assertOk()->assertDontSee('data-help-item open', false);

        $this->as('requester', '/help?q='.urlencode('question 2'))->assertOk()->assertSee('data-help-item open', false);
    }

    public function test_an_empty_help_page_still_renders(): void
    {
        $this->as('requester')->assertOk()->assertSee('No questions here yet');
        $this->app['auth']->forgetGuards();   // the person above is signed in; the next request is a visitor
        $this->get('/help-public')->assertOk()->assertSee('No questions here yet');
    }

    // ---------- Phase 2C: the "Still need help?" box and its settings ----------

    private function settingsPayload(array $extra = []): array
    {
        return $extra + [
            'sla_hours_routine' => \App\Models\Setting::int('sla_hours_routine'),
            'sla_hours_emergency' => \App\Models\Setting::int('sla_hours_emergency'),
            'reopen_window_days' => \App\Models\Setting::int('reopen_window_days'),
        ];
    }

    public function test_the_help_box_shows_the_standard_text_until_it_is_changed(): void
    {
        $hours = config('school.settings_defaults.help_office_hours');
        $email = config('school.settings_defaults.help_contact_email');

        $this->as('requester')->assertOk()
            ->assertSee('Still need help?')
            ->assertSee($hours)
            ->assertSee('href="mailto:'.$email.'"', false);
    }

    public function test_the_help_box_reads_the_settings(): void
    {
        \App\Models\Setting::put('help_office_hours', 'Mon to Fri, 9 AM to 4 PM');
        \App\Models\Setting::put('help_contact_email', 'help@example.test');

        $this->as('requester')->assertOk()
            ->assertSee('Mon to Fri, 9 AM to 4 PM')
            ->assertSee('href="mailto:help@example.test"', false)
            ->assertDontSee(config('school.settings_defaults.help_contact_email'));
    }

    public function test_the_repair_button_is_for_signed_in_people_only(): void
    {
        // the visitor first (a later actingAs would keep the person signed in)
        $this->get('/help-public')->assertOk()
            ->assertDontSee('Send a repair request')
            ->assertDontSee(route('repairs.create'), false)
            ->assertSee('To send a repair request');

        $this->as('requester')->assertOk()
            ->assertSee('Send a repair request')
            ->assertSee('href="'.route('repairs.create').'"', false);
    }

    public function test_the_text_of_the_box_is_escaped_and_a_bad_email_is_not_linked(): void
    {
        \App\Models\Setting::put('help_office_hours', '<script>alert(1)</script>');
        \App\Models\Setting::put('help_contact_email', 'not an email');

        $this->as('requester')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('mailto:', false);
    }

    public function test_the_admin_settings_page_has_the_help_fields(): void
    {
        $this->as('maintenance', route('admin.settings.edit', [], false))->assertOk()
            ->assertSee('name="help_office_hours"', false)
            ->assertSee('name="help_contact_email"', false)
            ->assertSee(config('school.settings_defaults.help_contact_email'));
    }

    public function test_the_admin_can_save_the_help_texts(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'help_office_hours' => 'Mon to Fri, 9 AM to 4 PM',
                'help_contact_email' => 'help@example.test',
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Mon to Fri, 9 AM to 4 PM', \App\Models\Setting::get('help_office_hours'));
        $this->assertSame('help@example.test', \App\Models\Setting::get('help_contact_email'));
    }

    public function test_a_bad_email_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->put(route('admin.settings.update'), $this->settingsPayload(['help_contact_email' => 'nope']))
            ->assertSessionHasErrors('help_contact_email');

        $this->assertSame(config('school.settings_defaults.help_contact_email'), \App\Models\Setting::get('help_contact_email'));
    }

    public function test_a_blank_field_goes_back_to_the_standard_text(): void
    {
        \App\Models\Setting::put('help_office_hours', 'Custom hours');

        $this->actingAs($this->user('maintenance'))
            ->put(route('admin.settings.update'), $this->settingsPayload(['help_office_hours' => '']))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame(config('school.settings_defaults.help_office_hours'), \App\Models\Setting::get('help_office_hours'));
    }

    public function test_saving_without_the_help_fields_leaves_them_alone(): void
    {
        \App\Models\Setting::put('help_office_hours', 'Custom hours');
        \App\Models\Setting::put('help_contact_email', 'custom@example.test');

        $this->actingAs($this->user('maintenance'))
            ->put(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Custom hours', \App\Models\Setting::get('help_office_hours'));
        $this->assertSame('custom@example.test', \App\Models\Setting::get('help_contact_email'));
    }
}