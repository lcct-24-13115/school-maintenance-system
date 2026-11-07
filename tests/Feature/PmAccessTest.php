<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PM E2: who may look at / change the PM Scheduler.
 *   manage_pm_schedules  = look + change (Maintenance Admin)
 *   view_pm_schedules    = look only (President)
 *   view_pm_reports      = the PM compliance report only (Finance; built in E3)
 */
class PmAccessTest extends TestCase
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

    private function facilityId(): int
    {
        return (int) Facility::where('name', 'Conference Room')->value('id');
    }

    private function schedule(): PmSchedule
    {
        return PmSchedule::create([
            'title' => 'Aircon Cleaning',
            'facility_id' => $this->facilityId(),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->user('maintenance')->id,
        ]);
    }

    private function equipment(): Equipment
    {
        return Equipment::create([
            'name' => 'Aircon Room 101',
            'facility_id' => $this->facilityId(),
            'category' => 'Air Conditioning',
            'serial_no' => 'SN-E2-001',
        ]);
    }

    private function feedUrl(): string
    {
        return route('maintenance-calendar.events', ['start' => '2026-11-01', 'end' => '2026-11-30']);
    }

    public function test_the_maintenance_admin_and_the_president_can_look_at_every_pm_page(): void
    {
        $schedule = $this->schedule();
        $equipment = $this->equipment();

        foreach (['maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who));

            $this->get(route('admin.pm-schedules.index'))->assertOk();
            $this->get(route('admin.pm-schedules.history', $schedule))->assertOk();
            $this->get(route('admin.equipment.index'))->assertOk();
            $this->get(route('admin.equipment.history', $equipment))->assertOk();
            $this->getJson($this->feedUrl())->assertOk();
        }
    }

    public function test_every_other_role_gets_403_on_every_pm_page(): void
    {
        $schedule = $this->schedule();
        $equipment = $this->equipment();

        foreach (['finance', 'custodian', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->actingAs($this->user($who));

            $this->get(route('admin.pm-schedules.index'))->assertForbidden();
            $this->get(route('admin.pm-schedules.history', $schedule))->assertForbidden();
            $this->get(route('admin.equipment.index'))->assertForbidden();
            $this->get(route('admin.equipment.history', $equipment))->assertForbidden();
            $this->getJson($this->feedUrl())->assertForbidden();
        }
    }

    public function test_the_president_can_look_but_cannot_change_anything(): void
    {
        $schedule = $this->schedule();
        $equipment = $this->equipment();
        $this->actingAs($this->user('president'));

        $this->get(route('admin.pm-schedules.create'))->assertForbidden();
        $this->post(route('admin.pm-schedules.store'), [
            'title' => 'Sneaky', 'facility_id' => $this->facilityId(), 'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'frequency_type' => 'monthly', 'frequency_interval' => 1, 'start_date' => '2026-12-01', 'lead_time_days' => 7,
        ])->assertForbidden();
        $this->get(route('admin.pm-schedules.edit', $schedule))->assertForbidden();
        $this->put(route('admin.pm-schedules.update', $schedule), ['title' => 'Changed'])->assertForbidden();
        $this->patch(route('admin.pm-schedules.toggle', $schedule))->assertForbidden();
        $this->post(route('admin.pm-schedules.generate', $schedule))->assertForbidden();
        $this->post(route('admin.pm-schedules.duplicate', $schedule))->assertForbidden();
        $this->post(route('admin.pm-schedules.bulk'), ['action' => 'pause', 'ids' => [$schedule->id]])->assertForbidden();
        $this->delete(route('admin.pm-schedules.destroy', $schedule))->assertForbidden();

        $this->post(route('admin.equipment.store'), ['name' => 'Sneaky', 'facility_id' => $this->facilityId()])->assertForbidden();
        $this->put(route('admin.equipment.update', $equipment), ['name' => 'Changed', 'facility_id' => $this->facilityId()])->assertForbidden();
        $this->delete(route('admin.equipment.destroy', $equipment))->assertForbidden();

        $this->assertSame(1, PmSchedule::count());
        $this->assertSame('Aircon Cleaning', $schedule->fresh()->title);
        $this->assertTrue($schedule->fresh()->is_active);
        $this->assertSame(1, Equipment::count());
        $this->assertSame('Aircon Room 101', $equipment->fresh()->name);
    }

    public function test_the_president_sees_no_buttons_that_change_data(): void
    {
        $schedule = $this->schedule();
        $this->equipment();

        $this->actingAs($this->user('president'));

        $this->get(route('admin.pm-schedules.index'))
            ->assertOk()
            ->assertSee('Aircon Cleaning')
            ->assertSee(route('admin.pm-schedules.history', $schedule), false)
            ->assertSee('You can look at the PM schedules but not change them.')
            ->assertDontSee('New schedule')
            ->assertDontSee('Make the ticket for the next due date now')
            ->assertDontSee(route('admin.pm-schedules.edit', $schedule), false)
            ->assertDontSee(route('admin.pm-schedules.toggle', $schedule), false)
            ->assertDontSee('value="DELETE"', false)
            ->assertDontSee('Ticked schedules');

        $this->get(route('admin.equipment.index'))
            ->assertOk()
            ->assertSee('Aircon Room 101')
            ->assertSee('You can look at the equipment list but not change it.')
            ->assertDontSee('Add equipment')
            ->assertDontSee('name="serial_no"', false)
            ->assertDontSee('value="DELETE"', false);
    }

    public function test_the_maintenance_admin_still_sees_every_button(): void
    {
        $schedule = $this->schedule();
        $this->equipment();

        $this->actingAs($this->user('maintenance'));

        $this->get(route('admin.pm-schedules.index'))
            ->assertOk()
            ->assertSee('New schedule')
            ->assertSee('Make the ticket for the next due date now')
            ->assertSee(route('admin.pm-schedules.edit', $schedule), false)
            ->assertSee('Ticked schedules')
            ->assertDontSee('You can look at the PM schedules but not change them.');

        $this->get(route('admin.equipment.index'))
            ->assertOk()
            ->assertSee('Add equipment')
            ->assertDontSee('You can look at the equipment list but not change it.');
    }

    public function test_the_menu_links_follow_the_view_permission(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('tickets.index'))
            ->assertOk()->assertSee(route('admin.pm-schedules.index'), false);

        $this->actingAs($this->user('president'))->get(route('tickets.index'))
            ->assertOk()->assertSee(route('admin.pm-schedules.index'), false);

        foreach (['finance', 'custodian'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.index'))
                ->assertOk()->assertDontSee(route('admin.pm-schedules.index'), false);
        }
    }

    public function test_the_default_matrix_for_looking_and_for_the_compliance_report(): void
    {
        $expected = [
            //               view PM, PM report
            'maintenance' => [true, true],
            'president' => [true, true],
            'finance' => [false, true],
            'custodian' => [false, false],
            'requester' => [false, false],
            'depthead' => [false, false],
            'treasurer' => [false, false],
        ];

        foreach ($expected as $who => [$view, $report]) {
            $u = $this->user($who);

            $this->assertSame($view, $u->canViewPm(), "{$who}: canViewPm");
            $this->assertSame($report, $u->canViewPmReports(), "{$who}: canViewPmReports");
        }
    }

    public function test_an_inactive_account_can_do_nothing(): void
    {
        $president = $this->user('president');
        $president->update(['is_active' => false]);

        $this->assertFalse($president->fresh()->canViewPm());
        $this->assertFalse($president->fresh()->canViewPmReports());
    }

    public function test_the_matrix_is_config_driven(): void
    {
        $this->schedule();

        // Give the Custodian read-only access: pages open, changes stay closed.
        config(['permissions.custodian' => ['view_pm_schedules']]);

        $this->actingAs($this->user('custodian'));
        $this->get(route('admin.pm-schedules.index'))->assertOk();
        $this->getJson($this->feedUrl())->assertOk();
        $this->get(route('admin.pm-schedules.create'))->assertForbidden();

        // Take the President's read access away again.
        config(['permissions.president' => ['cancel_own', 'reopen_own']]);

        $this->actingAs($this->user('president'));
        $this->get(route('admin.pm-schedules.index'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.pm-schedules.index'))->assertRedirect(route('login'));
        $this->getJson($this->feedUrl())->assertUnauthorized();
    }
}
