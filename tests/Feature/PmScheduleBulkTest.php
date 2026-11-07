<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PM schedules: bulk pause / resume, duplicate, and the per-schedule audit history. */
class PmScheduleBulkTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
    }

    private function schedule(string $title, bool $active = true): PmSchedule
    {
        return PmSchedule::create([
            'title' => $title,
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => $active,
            'created_by' => $this->admin()->id,
        ]);
    }

    public function test_pause_many_writes_one_audit_line_each(): void
    {
        $a = $this->schedule('Aircon Cleaning');
        $b = $this->schedule('Generator Checkup');

        $this->actingAs($this->admin())
            ->post(route('admin.pm-schedules.bulk'), ['action' => 'pause', 'ids' => [$a->id, $b->id]])
            ->assertRedirect()->assertSessionHas('status', '2 schedules paused.');

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertSame(2, AuditLog::where('action', AuditLogger::PM_SCHEDULE_CHANGED)->where('description', 'like', 'PM schedule deactivated:%')->count());
    }

    public function test_resume_moves_the_next_due_date_to_the_future_and_skips_the_active_ones(): void
    {
        $paused = $this->schedule('Aircon Cleaning', false);
        $active = $this->schedule('Generator Checkup');

        Carbon::setTestNow('2026-12-20 09:00:00');

        $this->actingAs($this->admin())
            ->post(route('admin.pm-schedules.bulk'), ['action' => 'resume', 'ids' => [$paused->id, $active->id]])
            ->assertSessionHas('status', '1 schedule resumed. 1 already active.');

        $paused->refresh();
        $this->assertTrue($paused->is_active);
        $this->assertSame('2027-01-15', $paused->next_due_date->toDateString());   // never a date in the past
    }

    public function test_a_bulk_request_needs_a_choice_and_a_valid_action(): void
    {
        $a = $this->schedule('Aircon Cleaning');

        $this->actingAs($this->admin())
            ->post(route('admin.pm-schedules.bulk'), ['action' => 'pause'])->assertSessionHasErrors('ids');

        $this->post(route('admin.pm-schedules.bulk'), ['action' => 'delete', 'ids' => [$a->id]])->assertSessionHasErrors('action');

        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_duplicate_makes_an_inactive_copy_with_the_checklist(): void
    {
        $src = $this->schedule('Aircon Cleaning');
        $src->checklistItems()->create(['label' => 'Clean the filter', 'sort_order' => 0]);
        $src->checklistItems()->create(['label' => 'Check the gas', 'sort_order' => 1]);

        $this->actingAs($this->admin())->post(route('admin.pm-schedules.duplicate', $src))->assertRedirect();

        $copy = PmSchedule::where('title', 'Copy of Aircon Cleaning')->firstOrFail();

        $this->assertFalse($copy->is_active);
        $this->assertSame($src->facility_id, $copy->facility_id);
        $this->assertSame($this->admin()->id, $copy->created_by);
        $this->assertSame(['Clean the filter', 'Check the gas'], $copy->checklistItems()->pluck('label')->all());
        $this->assertNull($copy->last_generated_at);
        $this->assertSame(2, PmSchedule::count());
        $this->assertSame(1, AuditLog::where('subject_id', $copy->id)->where('description', 'like', 'PM schedule duplicated:%')->count());
    }

    public function test_others_get_403(): void
    {
        $a = $this->schedule('Aircon Cleaning');
        $finance = User::where('email', 'finance@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($finance)->post(route('admin.pm-schedules.bulk'), ['action' => 'pause', 'ids' => [$a->id]])->assertForbidden();
        $this->post(route('admin.pm-schedules.duplicate', $a))->assertForbidden();
        $this->get(route('admin.pm-schedules.history', $a))->assertForbidden();

        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_the_history_shows_who_did_what(): void
    {
        $a = $this->schedule('Aircon Cleaning');

        $this->actingAs($this->admin())->patch(route('admin.pm-schedules.toggle', $a))->assertRedirect();

        $this->get(route('admin.pm-schedules.history', $a))
            ->assertOk()
            ->assertSee('Aircon Cleaning')
            ->assertSee('PM schedule deactivated: Aircon Cleaning')
            ->assertSee('Head of Maintenance');
    }

    public function test_the_list_has_the_bulk_controls(): void
    {
        $a = $this->schedule('Aircon Cleaning');

        $this->actingAs($this->admin())->get(route('admin.pm-schedules.index'))
            ->assertOk()
            ->assertSee(route('admin.pm-schedules.bulk'))
            ->assertSee(route('admin.pm-schedules.duplicate', $a))
            ->assertSee(route('admin.pm-schedules.history', $a));
    }
}