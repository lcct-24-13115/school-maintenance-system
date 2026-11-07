<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\PmScheduleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PM Scheduler Phase 1: schedules CRUD, validation, next due date, permission, seed data. */
class PmScheduleTest extends TestCase
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

        Carbon::setTestNow('2026-11-08 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function facilityId(string $name = 'Conference Room'): int
    {
        return Facility::where('name', $name)->value('id');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'Aircon Cleaning',
            'facility_id' => $this->facilityId(),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'frequency_type' => 'quarterly',
            'frequency_interval' => 1,
            'start_date' => '2026-12-01',
            'lead_time_days' => 7,
            'is_active' => 1,
        ], $over);
    }

    public function test_maintenance_admin_sees_the_list_and_others_get_403(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.pm-schedules.index'))->assertOk()->assertSee('Preventive maintenance');
        $this->actingAs($this->user('maintenance'))->get(route('admin.equipment.index'))->assertOk();

        $this->actingAs($this->user('custodian'))->get(route('admin.pm-schedules.index'))->assertForbidden();
        $this->actingAs($this->user('custodian'))->post(route('admin.pm-schedules.store'), $this->payload())->assertForbidden();
    }

    public function test_creating_a_schedule_computes_next_due_date_and_writes_the_audit_trail(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.pm-schedules.store'), $this->payload())
            ->assertRedirect(route('admin.pm-schedules.index'));

        $schedule = PmSchedule::firstOrFail();
        $this->assertSame('2026-12-01', $schedule->next_due_date->toDateString());
        $this->assertSame(1, AuditLog::where('action', 'pm_schedule_changed')->count());
    }

    public function test_a_start_date_in_the_past_moves_next_due_to_the_next_future_date(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.pm-schedules.store'), $this->payload(['start_date' => '2026-01-15']));

        // Jan 15, Apr 15, Jul 15, Oct 15 (past), then Jan 15 2027
        $this->assertSame('2027-01-15', PmSchedule::firstOrFail()->next_due_date->toDateString());
    }

    public function test_month_end_does_not_overflow_or_drift(): void
    {
        $s = new PmSchedule(['frequency_type' => 'monthly', 'frequency_interval' => 1, 'start_date' => '2026-01-31']);

        $this->assertSame('2026-02-28', $s->firstDueOnOrAfter('2026-02-10'));
        $this->assertSame('2026-03-31', $s->firstDueOnOrAfter('2026-03-01'));
    }

    public function test_an_ended_schedule_has_no_next_due_date(): void
    {
        $s = new PmSchedule(['frequency_type' => 'monthly', 'frequency_interval' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-03-01']);

        $this->assertNull($s->firstDueOnOrAfter('2026-11-08'));
    }

    public function test_validation_rejects_bad_dates_interval_and_foreign_equipment(): void
    {
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload(['start_date' => '2026-12-10', 'end_date' => '2026-12-01']))
            ->assertSessionHasErrors('end_date');

        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload(['frequency_interval' => 0]))
            ->assertSessionHasErrors('frequency_interval');

        $gym = Equipment::create(['name' => 'Standby Generator', 'facility_id' => $this->facilityId('Gymnasium')]);
        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload(['equipment_id' => $gym->id]))
            ->assertSessionHasErrors('equipment_id');

        $this->assertSame(0, PmSchedule::count());
    }

    public function test_update_recomputes_next_due_date_and_toggle_pauses_and_resumes(): void
    {
        $admin = $this->user('maintenance');
        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload());
        $schedule = PmSchedule::firstOrFail();

        $this->actingAs($admin)->put(route('admin.pm-schedules.update', $schedule), $this->payload(['start_date' => '2026-11-20', 'frequency_type' => 'monthly']))
            ->assertRedirect(route('admin.pm-schedules.index'));
        $this->assertSame('2026-11-20', $schedule->fresh()->next_due_date->toDateString());

        $this->actingAs($admin)->patch(route('admin.pm-schedules.toggle', $schedule));
        $this->assertFalse($schedule->fresh()->is_active);

        $this->actingAs($admin)->patch(route('admin.pm-schedules.toggle', $schedule));
        $this->assertTrue($schedule->fresh()->is_active);
    }

    public function test_delete_removes_the_schedule(): void
    {
        $admin = $this->user('maintenance');
        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload());

        $this->actingAs($admin)->delete(route('admin.pm-schedules.destroy', PmSchedule::firstOrFail()))
            ->assertRedirect(route('admin.pm-schedules.index'));

        $this->assertSame(0, PmSchedule::count());
    }

    public function test_equipment_with_schedules_cannot_be_deleted(): void
    {
        $admin = $this->user('maintenance');
        $item = Equipment::create(['name' => 'Split-type Aircon', 'facility_id' => $this->facilityId()]);
        $this->actingAs($admin)->post(route('admin.pm-schedules.store'), $this->payload(['equipment_id' => $item->id]));

        $this->actingAs($admin)->delete(route('admin.equipment.destroy', $item))->assertSessionHas('error');
        $this->assertNotNull($item->fresh());
    }

    public function test_the_seeder_makes_the_two_sample_schedules_once(): void
    {
        $this->seed(PmScheduleSeeder::class);
        $this->seed(PmScheduleSeeder::class);

        $this->assertSame(2, PmSchedule::count());
        $this->assertSame('quarterly', PmSchedule::where('title', 'Aircon Cleaning')->value('frequency_type'));
        $this->assertSame('monthly', PmSchedule::where('title', 'Generator Checkup')->value('frequency_type'));
    }
}