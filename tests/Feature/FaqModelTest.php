<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use App\Support\FaqText;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Help & FAQ Phase 1: the table, who sees which entry, the safe answer format, the starter seeder and the capability. */
class FaqModelTest extends TestCase
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

    /** @return list<string> the questions a person may read, in display order */
    private function seen(?User $user): array
    {
        return Faq::visibleTo($user)->pluck('question')->all();
    }

    private function entries(): void
    {
        $this->faq('A everyone', ['sort_order' => 10]);
        $this->faq('B custodian only', ['audience' => ['custodian'], 'sort_order' => 20]);
        $this->faq('C admin and president', ['audience' => ['maintenance_admin', 'president'], 'sort_order' => 30]);
        $this->faq('D hidden', ['is_published' => false, 'sort_order' => 40]);
        $this->faq('E public', ['is_public' => true, 'sort_order' => 50]);
    }

    public function test_the_audience_is_stored_as_a_list(): void
    {
        $faq = $this->faq('Q', ['audience' => ['custodian', 'finance']])->fresh();

        $this->assertSame(['custodian', 'finance'], $faq->audience);
        $this->assertSame(['custodian', 'finance'], $faq->audienceList());
        $this->assertFalse($faq->isForEveryone());
    }

    public function test_an_empty_audience_means_everyone(): void
    {
        $faq = $this->faq('Q', ['audience' => null])->fresh();

        $this->assertSame(['all'], $faq->audienceList());
        $this->assertTrue($faq->isForEveryone());

        $this->assertSame(['all'], $this->faq('Q2', ['audience' => []])->fresh()->audienceList());
    }

    public function test_scopes(): void
    {
        $this->entries();

        $this->assertSame(['A everyone', 'B custodian only', 'C admin and president', 'E public'], Faq::published()->ordered()->pluck('question')->all());
        $this->assertSame(['E public'], Faq::publicOnly()->pluck('question')->all());
    }

    public function test_each_role_sees_only_what_is_meant_for_it(): void
    {
        $this->entries();

        $this->assertSame(['A everyone', 'B custodian only', 'E public'], $this->seen($this->user('custodian')));
        $this->assertSame(['A everyone', 'C admin and president', 'E public'], $this->seen($this->user('maintenance')));
        $this->assertSame(['A everyone', 'C admin and president', 'E public'], $this->seen($this->user('president')));

        foreach (['finance', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->assertSame(['A everyone', 'E public'], $this->seen($this->user($who)), $who);
        }
    }

    public function test_a_visitor_sees_only_public_entries_for_everyone(): void
    {
        $this->entries();
        $this->faq('F public but for custodians', ['audience' => ['custodian'], 'is_public' => true, 'sort_order' => 60]);
        $this->faq('G public but hidden', ['is_public' => true, 'is_published' => false, 'sort_order' => 70]);

        $this->assertSame(['E public'], $this->seen(null));
    }

    public function test_a_hidden_entry_is_seen_by_nobody(): void
    {
        $hidden = $this->faq('Hidden', ['is_published' => false, 'is_public' => true]);

        foreach (['maintenance', 'president', 'finance', 'custodian', 'requester'] as $who) {
            $this->assertFalse($hidden->isVisibleTo($this->user($who)), $who);
        }
        $this->assertFalse($hidden->isVisibleTo(null));
    }

    public function test_the_order_is_sort_order_then_id(): void
    {
        $this->faq('Third', ['sort_order' => 30]);
        $this->faq('First', ['sort_order' => 10]);
        $this->faq('Second a', ['sort_order' => 20]);
        $this->faq('Second b', ['sort_order' => 20]);

        $this->assertSame(['First', 'Second a', 'Second b', 'Third'], $this->seen($this->user('requester')));
    }

    public function test_the_answer_is_escaped(): void
    {
        $html = $this->faq('Q', ['answer' => '<script>alert(1)</script> and <b>bold</b> & "quotes"'])->fresh()->answer_html;

        $this->assertStringNotContainsString('<script>', (string) $html);
        $this->assertStringNotContainsString('<b>', (string) $html);
        $this->assertStringContainsString('&lt;script&gt;', (string) $html);
        $this->assertStringContainsString('&amp;', (string) $html);
    }

    public function test_paragraphs_bullets_steps_and_bold(): void
    {
        $this->assertSame('<p>One</p>'."\n".'<p>Two<br>still two</p>', FaqText::toHtml("One\n\nTwo\nstill two"));

        $this->assertSame("<ul>\n<li>a</li>\n<li>b</li>\n</ul>", FaqText::toHtml("- a\n- b"));
        $this->assertSame("<ol>\n<li>first</li>\n<li>second</li>\n</ol>", FaqText::toHtml("1. first\n2) second"));

        $this->assertSame('<p>Press <strong>Submit</strong> now</p>', FaqText::toHtml('Press **Submit** now'));

        // an intro line, then a list, then a closing line
        $this->assertSame(
            "<p>Steps:</p>\n<ol>\n<li>one</li>\n<li>two</li>\n</ol>\n<p>Done.</p>",
            FaqText::toHtml("Steps:\n1. one\n2. two\n\nDone.")
        );
    }

    public function test_bold_cannot_break_out_of_escaping(): void
    {
        $html = FaqText::toHtml('**<img src=x onerror=alert(1)>**');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<strong>&lt;img', $html);
    }

    public function test_only_links_to_this_site_become_links(): void
    {
        $this->assertStringContainsString('<a href="/maintenance-calendar">PM calendar</a>', FaqText::toHtml('Open the [PM calendar](/maintenance-calendar).'));
        $this->assertStringContainsString('<a href="/help?topic=pm&amp;x=1">Help</a>', FaqText::toHtml('[Help](/help?topic=pm&x=1)'));

        foreach (['[x](//evil.example.com)', '[x](https://evil.example.com)', '[x](javascript:alert(1))', '[x](/ok" onclick="alert(1))'] as $bad) {
            $this->assertStringNotContainsString('<a ', FaqText::toHtml($bad), $bad);
        }
    }

    public function test_the_starter_seeder_adds_the_entries_once(): void
    {
        $this->seed(FaqSeeder::class);
        $count = Faq::count();

        $this->assertSame(8, $count);

        $this->seed(FaqSeeder::class);
        $this->assertSame($count, Faq::count());
    }

    public function test_the_starter_file_is_valid(): void
    {
        $rows = require database_path('data/faq_starter.php');
        $roles = Faq::roleSlugs();
        $seen = [];

        foreach ($rows as [$topic, $audience, $public, $question, $answer, $order]) {
            $this->assertNotSame('', trim($topic));
            $this->assertNotSame('', trim($question));
            $this->assertNotSame('', trim($answer));
            $this->assertIsBool($public);
            $this->assertIsInt($order);
            $this->assertArrayNotHasKey($question, $seen, "duplicate question: {$question}");
            $seen[$question] = true;

            foreach ($audience as $a) {
                $this->assertTrue($a === Faq::ALL || in_array($a, $roles, true), "unknown audience {$a}");
            }
            if ($public) {
                $this->assertSame(['all'], $audience, "a public entry must be for everyone: {$question}");
            }
        }
    }

    public function test_the_seeder_never_changes_an_entry_that_is_already_there(): void
    {
        $this->seed(FaqSeeder::class);
        $faq = Faq::where('question', 'How do I sign in?')->firstOrFail();
        $faq->update(['answer' => 'Edited by the admin.', 'is_published' => false]);

        $this->artisan('faq:seed')->assertExitCode(0);

        $faq->refresh();
        $this->assertSame('Edited by the admin.', $faq->answer);
        $this->assertFalse($faq->is_published);
        $this->assertSame(8, Faq::count());
    }

    public function test_the_command_adds_what_is_missing(): void
    {
        $this->artisan('faq:seed')->assertExitCode(0);
        $this->assertSame(8, Faq::count());

        Faq::where('question', 'How do I accept a task?')->delete();
        $this->artisan('faq:seed')->assertExitCode(0);
        $this->assertSame(8, Faq::count());
    }

    public function test_the_starter_entries_are_visible_to_the_right_people(): void
    {
        $this->seed(FaqSeeder::class);

        $visitor = $this->seen(null);
        $this->assertCount(3, $visitor);
        $this->assertContains('How do I sign in?', $visitor);

        $this->assertContains('How do I accept a task?', $this->seen($this->user('maintenance')));
        $this->assertNotContains('How do I accept a task?', $this->seen($this->user('requester')));
        $this->assertContains('How do I send a repair request?', $this->seen($this->user('requester')));
    }

    public function test_only_the_maintenance_admin_may_manage_the_faq(): void
    {
        foreach ((array) config('permissions') as $slug => $capabilities) {
            $this->assertSame($slug === 'maintenance_admin', in_array('manage_faq', $capabilities, true), $slug);
        }

        $this->assertTrue($this->user('maintenance')->canManageFaq());

        foreach (['president', 'finance', 'custodian', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->assertFalse($this->user($who)->canManageFaq(), $who);
        }
    }

    public function test_the_creator_is_remembered(): void
    {
        $admin = $this->user('maintenance');
        $faq = $this->faq('Q', ['created_by' => $admin->id, 'updated_by' => $admin->id]);

        $this->assertTrue($faq->creator->is($admin));
        $this->assertTrue($faq->updater->is($admin));
    }
}
