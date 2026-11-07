<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmJobRun;
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

/** PM Scheduler Phase 2: recurrence edge cases, automatic ticket generation, idempotency, catch-up, manual button, command. */
class PmTicketGeneratorTest extends TestCase
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

    private function schedule(array $over = []): PmSchedule
    {
        return PmSchedule::create(array_merge([
            'title' => 'Aircon Cleaning',
            'description' => 'Clean the filters.',
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

    public function test_a_due_schedule_makes_one_ticket_and_moves_to_the_next_date(): void
    {
        $s = $this->schedule();   // due Nov 15, ticket from Nov 8

        $run = PmTicketGenerator::runDue('command');

        $this->assertSame(1, $run->schedules_checked);
        $this->assertSame(1, $run->tickets_created);

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame($s->id, $ticket->pm_schedule_id);
        $this->assertSame('2026-11-15', $ticket->pm_due_date->toDateString());
        $this->assertSame(TicketStatus::Assigned, $ticket->status);
        $this->assertSame($this->admin()->id, $ticket->assigned_to);
        $this->assertSame('preventive', $ticket->maintenance_type);
        $this->assertMatchesRegularExpression('/^MT-2026-\d{5}$/', $ticket->ticket_no);
        $this->assertStringContainsString('[PM] Aircon Cleaning - Nov 2026', $ticket->description);
        $this->assertStringContainsString('Conference Room', $ticket->location);

        $this->assertSame('2026-12-15', $s->fresh()->next_due_date->toDateString());
        $this->assertNotNull($s->fresh()->last_generated_at);
    }

    public function test_a_schedule_that_is_not_due_yet_makes_nothing(): void
    {
        $this->schedule(['start_date' => '2026-12-01']);   // ticket from Nov 24

        $run = PmTicketGenerator::runDue('command');

        $this->assertSame(0, $run->schedules_checked);
        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_running_twice_on_the_same_day_makes_no_duplicate(): void
    {
        $this->schedule();

        PmTicketGenerator::runDue('command');
        $second = PmTicketGenerator::runDue('command');

        $this->assertSame(0, $second->tickets_created);
        $this->assertSame(1, MaintenanceRequest::count());
    }

    public function test_the_database_blocks_a_duplicate_even_if_the_schedule_was_not_advanced(): void
    {
        $s = $this->schedule();
        PmTicketGenerator::runDue('command');

        // pretend the job crashed right after making the ticket: the schedule still points at the old date
        PmSchedule::whereKey($s->id)->update(['next_due_date' => '2026-11-15']);

        $run = PmTicketGenerator::runDue('command');

        $this->assertSame(0, $run->tickets_created);
        $this->assertSame(1, $run->skipped);
        $this->assertSame(1, MaintenanceRequest::count());
        $this->assertSame('2026-12-15', $s->fresh()->next_due_date->toDateString());
    }

    public function test_after_missed_days_only_one_ticket_is_made_and_the_next_date_is_in_the_future(): void
    {
        $s = $this->schedule(['start_date' => '2026-09-01']);
        $s->forceFill(['next_due_date' => '2026-09-01'])->save();   // the job did not run for two months

        $run = PmTicketGenerator::runDue('command');

        $this->assertSame(1, $run->tickets_created);
        $this->assertSame(1, MaintenanceRequest::count());

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame('2026-09-01', $ticket->pm_due_date->toDateString());
        $this->assertStringContainsString('missed', $ticket->description);
        $this->assertSame('2026-11-08', $ticket->due_at->toDateString());   // never a target date in the past
        $this->assertSame('2026-12-01', $s->fresh()->next_due_date->toDateString());

        $this->assertSame(0, PmTicketGenerator::runDue('command')->tickets_created);
    }

    public function test_inactive_and_ended_schedules_make_nothing(): void
    {
        $this->schedule(['is_active' => false]);
        $this->schedule(['title' => 'Old one', 'start_date' => '2026-01-01', 'end_date' => '2026-03-01']);

        $this->assertSame(0, PmTicketGenerator::runDue('command')->tickets_created);
        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_an_emergency_priority_schedule_flags_the_ticket(): void
    {
        $this->schedule(['ticket_priority' => PmSchedule::PRIORITY_EMERGENCY]);

        PmTicketGenerator::runDue('command');

        $this->assertTrue(MaintenanceRequest::firstOrFail()->is_emergency);
    }

    public function test_every_run_is_logged(): void
    {
        $this->schedule();

        PmTicketGenerator::runDue('schedule');

        $run = PmJobRun::firstOrFail();
        $this->assertSame('schedule', $run->trigger);
        $this->assertSame(1, $run->tickets_created);
        $this->assertSame(0, $run->error_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_title_follows_the_frequency(): void
    {
        $quarterly = new PmSchedule(['title' => 'Aircon Cleaning', 'frequency_type' => 'quarterly']);
        $weekly = new PmSchedule(['title' => 'Check', 'frequency_type' => 'weekly']);

        $this->assertSame('[PM] Aircon Cleaning - Q4 2026', PmTicketGenerator::title($quarterly, Carbon::parse('2026-12-01')));
        $this->assertSame('[PM] Check - Nov 20, 2026', PmTicketGenerator::title($weekly, Carbon::parse('2026-11-20')));
    }

    public function test_a_leap_day_start_moves_to_feb_28_in_a_normal_year(): void
    {
        $s = new PmSchedule(['frequency_type' => 'yearly', 'frequency_interval' => 1, 'start_date' => '2028-02-29']);

        $this->assertSame('2029-02-28', $s->occurrence(1)->toDateString());
        $this->assertSame('2032-02-29', $s->occurrence(4)->toDateString());
    }

    public function test_the_ticket_now_button_makes_a_ticket_before_the_lead_time(): void
    {
        $s = $this->schedule(['start_date' => '2026-12-20']);   // normally not due until Dec 13

        $this->actingAs($this->admin())->post(route('admin.pm-schedules.generate', $s))->assertSessionHas('status');

        $this->assertSame(1, MaintenanceRequest::count());
        $this->assertSame('2027-01-20', $s->fresh()->next_due_date->toDateString());
        $this->assertSame('manual', PmJobRun::firstOrFail()->trigger);
    }

    public function test_the_ticket_now_button_needs_permission_and_an_active_schedule(): void
    {
        $s = $this->schedule();

        $custodian = User::where('email', 'custodian@lcctanauan.edu.ph')->firstOrFail();
        $this->actingAs($custodian)->post(route('admin.pm-schedules.generate', $s))->assertForbidden();

        $s->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin())->post(route('admin.pm-schedules.generate', $s))->assertSessionHas('error');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_the_command_dry_run_makes_nothing_and_the_real_run_makes_the_ticket(): void
    {
        $this->schedule();

        $this->artisan('pm:generate', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, MaintenanceRequest::count());

        $this->artisan('pm:generate')->assertSuccessful();
        $this->assertSame(1, MaintenanceRequest::count());
    }

    public function test_the_command_rejects_a_bad_date(): void
    {
        $this->artisan('pm:generate', ['--date' => '12/01/2026'])->assertFailed();
    }
}
