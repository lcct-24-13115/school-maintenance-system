<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\PmTicketGenerator;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** PM integration Phase A: PM badge + link back, checklist template and its copy on the ticket, fixed / floating option. */
class PmChecklistTest extends TestCase
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

        $this->travelTo(Carbon::parse('2026-11-08 09:00:00'));
    }

    private function admin(): User
    {
        return User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'Aircon Cleaning',
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'frequency_type' => 'quarterly',
            'frequency_interval' => 1,
            'start_date' => '2026-12-01',
            'lead_time_days' => 7,
            'is_active' => 1,
        ], $over);
    }

    private function schedule(array $over = []): PmSchedule
    {
        return PmSchedule::create(array_merge([
            'title' => 'Aircon Cleaning',
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'category_id' => Category::where('name', 'Air Conditioning')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->admin()->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->admin()->id,
        ], $over));
    }

    public function test_the_checklist_is_saved_one_task_per_line_and_replaced_on_edit(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload([
            'checklist' => "Clean the filter\nCheck the refrigerant\n\n- Test the thermostat\nClean the filter",
            'schedule_basis' => 'floating',
        ]))->assertRedirect(route('admin.pm-schedules.index'));

        $s = PmSchedule::firstOrFail();
        $this->assertSame('floating', $s->schedule_basis);
        $this->assertTrue($s->isFloating());
        $this->assertSame(['Clean the filter', 'Check the refrigerant', 'Test the thermostat'], $s->checklistItems->pluck('label')->all());

        $this->actingAs($admin)->put(route('admin.pm-schedules.update', $s), $this->payload(['checklist' => 'Only this']))
            ->assertRedirect(route('admin.pm-schedules.index'));

        $fresh = $s->fresh();
        $this->assertSame(['Only this'], $fresh->checklistItems->pluck('label')->all());
        $this->assertSame('floating', $fresh->schedule_basis);   // not sent = unchanged
    }

    public function test_a_new_schedule_is_fixed_by_default_and_a_bad_basis_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload())->assertRedirect();
        $this->assertSame('fixed', PmSchedule::firstOrFail()->fresh()->schedule_basis);

        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload(['schedule_basis' => 'weird']))
            ->assertSessionHasErrors('schedule_basis');
        $this->assertSame(1, PmSchedule::count());
    }

    public function test_the_ticket_gets_a_copy_of_the_checklist_that_later_edits_do_not_change(): void
    {
        $s = $this->schedule();
        $s->checklistItems()->create(['label' => 'Clean the filter', 'sort_order' => 0]);
        $s->checklistItems()->create(['label' => 'Test the thermostat', 'sort_order' => 1]);

        PmTicketGenerator::runDue('command');

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame(['Clean the filter', 'Test the thermostat'], $ticket->pmChecklist->pluck('label')->all());
        $this->assertFalse($ticket->pmChecklist->first()->is_done);

        $s->checklistItems()->delete();
        $this->assertCount(2, $ticket->fresh()->pmChecklist);
    }

    public function test_the_ticket_page_shows_the_pm_badge_the_checklist_and_the_link_back(): void
    {
        $s = $this->schedule();
        $s->checklistItems()->create(['label' => 'Clean the filter', 'sort_order' => 0]);

        PmTicketGenerator::runDue('command');
        $ticket = MaintenanceRequest::firstOrFail();

        $this->actingAs($this->admin())->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Preventive Maintenance')
            ->assertSee('Clean the filter')
            ->assertSee(route('admin.pm-schedules.edit', $s), false);
    }
}