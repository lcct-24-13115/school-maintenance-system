<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\PmTicketGenerator;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Maintenance history page of one piece of equipment: its PM tickets, the numbers, the filters, the permission. */
class EquipmentHistoryTest extends TestCase
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

    private function equipment(string $name): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'category' => 'Air Conditioning',
            'serial_no' => 'SN-'.strtoupper(substr(md5($name), 0, 6)),
        ]);
    }

    /** A schedule of $equipment and the one ticket it makes for Nov 15. */
    private function ticketFor(Equipment $equipment, string $title): MaintenanceRequest
    {
        $schedule = PmSchedule::create([
            'title' => $title,
            'facility_id' => $equipment->facility_id,
            'equipment_id' => $equipment->id,
            'category_id' => Category::where('name', 'Air Conditioning')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->admin()->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->admin()->id,
        ]);

        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');

        return $ticket;
    }

    public function test_the_admin_sees_the_history_of_this_equipment_only(): void
    {
        $aircon = $this->equipment('Aircon Room 101');
        $other = $this->equipment('Generator Set');

        $mine = $this->ticketFor($aircon, 'Aircon Cleaning');
        $theirs = $this->ticketFor($other, 'Generator Checkup');

        $this->actingAs($this->admin())->get(route('admin.equipment.history', $aircon))
            ->assertOk()
            ->assertSee('Aircon Room 101')
            ->assertSee('Aircon Cleaning')
            ->assertSee($mine->ticket_no)
            ->assertDontSee($theirs->ticket_no)
            ->assertDontSee('Generator Checkup');
    }

    public function test_others_get_403(): void
    {
        $aircon = $this->equipment('Aircon Room 101');

        foreach (['finance', 'custodian'] as $who) {
            $user = User::where('email', $who.'@lcctanauan.edu.ph')->firstOrFail();

            $this->actingAs($user)->get(route('admin.equipment.history', $aircon))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $aircon = $this->equipment('Aircon Room 101');

        $this->get(route('admin.equipment.history', $aircon))->assertRedirect(route('login'));
    }

    public function test_the_numbers_and_the_done_and_open_filters(): void
    {
        $aircon = $this->equipment('Aircon Room 101');

        $open = $this->ticketFor($aircon, 'Filter Cleaning');
        $done = $this->ticketFor($aircon, 'Coil Check');
        $done->forceFill(['pm_completed_date' => '2026-11-08', 'pm_technician_id' => $this->admin()->id, 'pm_notes' => 'All clean, no leaks'])->save();

        $this->actingAs($this->admin())->get(route('admin.equipment.history', $aircon))
            ->assertOk()
            ->assertSee($open->ticket_no)
            ->assertSee($done->ticket_no)
            ->assertSee('All clean, no leaks')
            ->assertViewHas('stats', fn (array $s) => $s['total'] === 2 && $s['done'] === 1 && $s['open'] === 2 && $s['overdue'] === 0);

        $this->get(route('admin.equipment.history', [$aircon, 'status' => 'done']))
            ->assertOk()->assertSee($done->ticket_no)->assertDontSee($open->ticket_no);

        $this->get(route('admin.equipment.history', [$aircon, 'schedule' => $open->pm_schedule_id]))
            ->assertOk()->assertSee($open->ticket_no)->assertDontSee($done->ticket_no);
    }

    public function test_a_ticket_past_its_due_date_counts_as_overdue(): void
    {
        $aircon = $this->equipment('Aircon Room 101');
        $ticket = $this->ticketFor($aircon, 'Aircon Cleaning');

        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->actingAs($this->admin())->get(route('admin.equipment.history', [$aircon, 'status' => 'overdue']))
            ->assertOk()
            ->assertSee($ticket->ticket_no)
            ->assertViewHas('stats', fn (array $s) => $s['overdue'] === 1);
    }

    public function test_an_equipment_without_tickets_says_so(): void
    {
        $aircon = $this->equipment('Aircon Room 101');

        $this->actingAs($this->admin())->get(route('admin.equipment.history', $aircon))
            ->assertOk()->assertSee('No maintenance has been recorded for this equipment yet.');
    }

    public function test_the_equipment_list_links_to_the_history(): void
    {
        $aircon = $this->equipment('Aircon Room 101');

        $this->actingAs($this->admin())->get(route('admin.equipment.index'))
            ->assertOk()->assertSee(route('admin.equipment.history', $aircon));
    }
}