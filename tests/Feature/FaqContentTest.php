<?php

namespace Tests\Feature;

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help & FAQ Phase 4B: the "source" column and the safe starter seeder.
 * (Phase 4F adds the content checks to this same file.)
 */
class FaqContentTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: list<string>, 2: bool, 3: string, 4: string, 5: int}> */
    private function rows(): array
    {
        return require database_path('data/faq_starter.php');
    }

    /** @return array{added: int, updated: int, unchanged: int, kept: int} */
    private function runSeeder(): array
    {
        return (new FaqSeeder)->sync();
    }

    private function firstQuestion(): string
    {
        return $this->rows()[0][3];
    }

    public function test_an_entry_made_by_hand_is_custom_by_default(): void
    {
        $faq = Faq::create([
            'topic' => 'Getting started', 'audience' => ['all'], 'is_public' => false,
            'question' => 'A question typed by an admin?', 'answer' => 'An answer.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $this->assertSame('custom', $faq->fresh()->source);
    }

    public function test_the_seeder_adds_every_row_as_starter(): void
    {
        $result = $this->runSeeder();

        $this->assertSame(count($this->rows()), $result['added']);
        $this->assertSame(count($this->rows()), Faq::where('source', 'starter')->count());
        $this->assertSame(0, Faq::where('source', 'custom')->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->runSeeder();
        $before = Faq::orderBy('id')->get()->map(fn (Faq $f) => [$f->id, $f->answer, (string) $f->updated_at])->all();

        $result = $this->runSeeder();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(count($this->rows()), $result['unchanged']);
        $this->assertSame(count($this->rows()), Faq::count());
        $this->assertSame($before, Faq::orderBy('id')->get()->map(fn (Faq $f) => [$f->id, $f->answer, (string) $f->updated_at])->all());
    }

    public function test_it_updates_a_starter_entry_nobody_edited(): void
    {
        $this->runSeeder();
        [$topic, , , $question, $answer] = $this->rows()[0];

        // a query-builder update fires no model events, so the row stays "starter" (like an old version of the file)
        Faq::where('question', $question)->update(['answer' => 'Old text from an older version.', 'topic' => 'Old topic']);

        $result = $this->runSeeder();

        $this->assertSame(1, $result['updated']);
        $faq = Faq::where('question', $question)->firstOrFail();
        $this->assertSame($answer, $faq->answer);
        $this->assertSame($topic, $faq->topic);
        $this->assertSame('starter', $faq->source);
    }

    public function test_an_entry_an_admin_edited_is_never_overwritten(): void
    {
        $this->runSeeder();
        $question = $this->firstQuestion();

        $faq = Faq::where('question', $question)->firstOrFail();
        $faq->update(['answer' => 'My own words.', 'topic' => 'My own topic', 'audience' => ['custodian'], 'is_public' => false]);

        $this->assertSame('custom', $faq->fresh()->source, 'editing the content must turn it into a custom entry');

        $result = $this->runSeeder();

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(1, Faq::where('question', $question)->count(), 'no second copy may be added');

        $faq->refresh();
        $this->assertSame('My own words.', $faq->answer);
        $this->assertSame('My own topic', $faq->topic);
        $this->assertSame(['custodian'], $faq->audience);
    }

    public function test_hiding_or_moving_a_starter_entry_is_kept_and_does_not_make_it_custom(): void
    {
        $this->runSeeder();
        $question = $this->firstQuestion();

        $faq = Faq::where('question', $question)->firstOrFail();
        $faq->update(['is_published' => false, 'sort_order' => 999]);

        $this->assertSame('starter', $faq->fresh()->source);

        $this->runSeeder();

        $faq->refresh();
        $this->assertFalse($faq->is_published);
        $this->assertSame(999, $faq->sort_order);
        $this->assertSame('starter', $faq->source);
    }

    public function test_custom_entries_are_never_deleted_or_changed(): void
    {
        $custom = Faq::create([
            'topic' => 'School rules', 'audience' => ['all'], 'is_public' => false,
            'question' => 'Where is the maintenance office?', 'answer' => 'Written by the admin.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(count($this->rows()) + 1, Faq::count());
        $this->assertSame('Written by the admin.', $custom->fresh()->answer);
        $this->assertSame('custom', $custom->fresh()->source);
    }

    public function test_a_custom_entry_with_the_same_question_stops_the_starter_copy(): void
    {
        [, , , $question] = $this->rows()[0];

        Faq::create([
            'topic' => 'Getting started', 'audience' => ['all'], 'is_public' => false,
            'question' => $question, 'answer' => 'The admin wrote this first.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $result = $this->runSeeder();

        $this->assertSame(1, $result['kept']);
        $this->assertSame(count($this->rows()) - 1, $result['added']);
        $this->assertSame(1, Faq::where('question', $question)->count());
        $this->assertSame('The admin wrote this first.', Faq::where('question', $question)->firstOrFail()->answer);
    }

    public function test_a_starter_entry_that_was_deleted_is_added_again(): void
    {
        $this->runSeeder();
        Faq::where('question', $this->firstQuestion())->delete();

        $result = $this->runSeeder();

        $this->assertSame(1, $result['added']);
        $this->assertSame(count($this->rows()), Faq::count());
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->artisan('faq:seed')
            ->expectsOutputToContain('added '.count($this->rows()))
            ->assertExitCode(0);

        $this->artisan('faq:seed')
            ->expectsOutputToContain('Nothing to change')
            ->assertExitCode(0);
    }
}