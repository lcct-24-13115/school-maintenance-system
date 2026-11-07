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
use Tests\TestCase;

/** Help & FAQ Phase 5D: keyboard, screen reader, phone and print support that can be checked without a browser. */
class FaqAccessibilityTest extends TestCase
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

    private function entries(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Faq::create([
                'topic' => 'Getting started',
                'audience' => ['all'],
                'is_public' => true,
                'question' => "Question number {$i} of the list",
                'answer' => "Answer number {$i}.",
                'sort_order' => $i * 10,
                'is_published' => true,
            ]);
        }
    }

    public function test_the_questions_are_native_details_and_summary_elements(): void
    {
        $this->entries(2);

        $html = $this->actingAs($this->user('requester'))->get(route('help.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<details class="help-item"'));
        $this->assertSame(2, substr_count($html, '<summary>'));
    }

    public function test_back_to_top_is_not_shown_on_a_short_list(): void
    {
        $this->entries(2);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertDontSee('Back to top')
            ->assertSee('id="help-top"', false);
    }

    public function test_back_to_top_shows_on_a_long_list_and_points_at_the_page(): void
    {
        $this->entries(6);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertSee('Back to top')
            ->assertSee('href="#help-top"', false)
            ->assertSee('tabindex="-1"', false);
    }

    public function test_the_print_line_and_the_accessibility_script_are_on_the_page_for_everybody(): void
    {
        $this->entries(1);

        $this->get(route('help.public'))
            ->assertOk()
            ->assertSee('help-print-head', false)
            ->assertSee('js/help-a11y.js', false);

        $this->actingAs($this->user('requester'))->get(route('help.index'))
            ->assertOk()
            ->assertSee('help-print-head', false)
            ->assertSee('js/help-a11y.js', false);
    }

    public function test_the_manager_buttons_that_only_show_an_icon_say_which_entry_they_are_for(): void
    {
        $this->entries(2);

        $this->actingAs($this->user('maintenance'))->get(route('admin.faq.index'))
            ->assertOk()
            ->assertSee('aria-label="Move up: Question number 2 of the list"', false)
            ->assertSee('aria-label="Move down: Question number 1 of the list"', false)
            ->assertSee('aria-label="Delete: Question number 1 of the list"', false);
    }

    public function test_the_stylesheet_has_focus_phone_and_print_rules(): void
    {
        $css = (string) file_get_contents(public_path('css/help.css'));

        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('overflow-wrap:anywhere', $css);
        $this->assertStringContainsString('.help-top', $css);

        $manager = (string) file_get_contents(public_path('css/phase6.css'));
        $this->assertStringContainsString('td[data-label="Actions"]', $manager);
    }

    public function test_the_accessibility_script_is_plain_javascript_with_no_dependency(): void
    {
        $js = (string) file_get_contents(public_path('js/help-a11y.js'));

        $this->assertStringContainsString('beforeprint', $js);
        $this->assertStringContainsString('afterprint', $js);
        $this->assertStringNotContainsString('import ', $js);
        $this->assertStringNotContainsString('require(', $js);
    }
}