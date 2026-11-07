<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Faq;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Help & FAQ Phase 4E: the <x-help-link> component and where it is placed.
 * It must show only where the person already may open the page, and only when the topic has something to read.
 */
class HelpLinkTest extends TestCase
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
        (new FaqSeeder)->sync();   // the real starter content
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function link(string $topic): string
    {
        return route('help.index', ['topic' => $topic]);
    }

    private function ticket(): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
    }

    private function rendered(string $blade, ?User $as): string
    {
        if ($as !== null) {
            $this->actingAs($as);
        }

        return trim(Blade::render($blade));
    }

    // ---- the component ---------------------------------------------------------------------------

    public function test_the_link_points_to_the_topic_page(): void
    {
        $html = $this->rendered('<x-help-link topic="Sending a repair request" />', $this->user('requester'));

        $this->assertStringContainsString($this->link('Sending a repair request'), $html);
        $this->assertStringContainsString('Help with this page', $html);
        $this->assertStringContainsString('class="help-link"', $html);
        $this->assertStringNotContainsString('help-link-row', $html);
    }

    public function test_the_label_and_the_row_can_be_set(): void
    {
        $html = $this->rendered('<x-help-link topic="Sending a repair request" label="Need help?" row />', $this->user('requester'));

        $this->assertStringContainsString('Need help?', $html);
        $this->assertStringContainsString('help-link-row', $html);
    }

    public function test_the_topic_page_opens_on_that_topic(): void
    {
        $this->actingAs($this->user('requester'))
            ->get($this->link('Following your request'))
            ->assertOk()
            ->assertSee('What do the statuses mean?');
    }

    public function test_a_visitor_gets_no_link(): void
    {
        $this->assertSame('', $this->rendered('<x-help-link topic="Getting started" />', null));
    }

    public function test_nothing_is_shown_when_the_person_cannot_read_the_topic(): void
    {
        // "Reports and records" is written for the maintenance admin, finance and the president only.
        $this->assertSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('requester')));
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('finance')));

        // The PM report is read by finance too, so finance gets the PM topic, a requester does not.
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Preventive maintenance" />', $this->user('finance')));
        $this->assertSame('', $this->rendered('<x-help-link topic="Preventive maintenance" />', $this->user('requester')));
    }

    public function test_an_unknown_topic_gives_no_link(): void
    {
        $this->assertSame('', $this->rendered('<x-help-link topic="No such topic" />', $this->user('maintenance')));
    }

    public function test_hiding_every_entry_of_a_topic_removes_the_link(): void
    {
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('president')));

        Faq::where('topic', 'Reports and records')->update(['is_published' => false]);

        $this->assertSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('president')));
    }

    // ---- where it is placed ----------------------------------------------------------------------

    public function test_the_repair_request_form_has_the_link(): void
    {
        $this->actingAs($this->user('requester'))
            ->get(route('repairs.create'))
            ->assertOk()
            ->assertSee($this->link('Sending a repair request'), false);
    }

    public function test_the_ticket_page_has_the_link(): void
    {
        $ticket = $this->ticket();

        foreach (['requester', 'maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('tickets.show', $ticket))
                ->assertOk()
                ->assertSee($this->link('Following your request'), false);
        }
    }

    public function test_the_pm_schedules_and_the_pm_calendar_have_the_link_for_the_roles_that_open_them(): void
    {
        foreach (['maintenance', 'president'] as $who) {
            foreach (['admin.pm-schedules.index', 'maintenance-calendar.index'] as $route) {
                $this->actingAs($this->user($who))
                    ->get(route($route))
                    ->assertOk()
                    ->assertSee($this->link('Preventive maintenance'), false);
            }
        }
    }

    public function test_the_pm_report_has_the_link_for_every_role_that_opens_it(): void
    {
        foreach (['maintenance', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('pm-reports.index'))
                ->assertOk()
                ->assertSee($this->link('Preventive maintenance'), false);
        }
    }

    public function test_the_reports_page_has_the_link_for_every_role_that_opens_it(): void
    {
        foreach (['maintenance', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('reports.index'))
                ->assertOk()
                ->assertSee($this->link('Reports and records'), false);
        }
    }

    public function test_the_link_is_not_a_way_into_a_page_the_role_cannot_open(): void
    {
        $requester = $this->user('requester');

        foreach (['reports.index', 'pm-reports.index', 'admin.pm-schedules.index', 'maintenance-calendar.index'] as $route) {
            $this->actingAs($requester)->get(route($route))->assertForbidden();
        }
    }
}