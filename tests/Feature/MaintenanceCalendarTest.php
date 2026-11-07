<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Equipment;
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

/** Maintenance Calendar Phase 1: the events feed (projection, ticket colours, filters, permission, range). */
class MaintenanceCalendarTest extends TestCase
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

    /** @return list<array<string, mixed>> */
    private function events(array $query = []): array
    {
        $query = array_merge(['start' => '2026-11-01', 'end' => '2026-12-31'], $query);

        return $this->actingAs($this->admin())->getJson(route('maintenance-calendar.events', $query))->assertOk()->json();
    }

    public function test_only_the_maintenance_admin_can_open_the_feed(): void
    {
        $url = route('maintenance-calendar.events', ['start' => '2026-11-01', 'end' => '2026-11-30']);

        $this->getJson($url)->assertUnauthorized();

        $custodian = User::where('email', 'custodian@lcctanauan.edu.ph')->firstOrFail();
        $this->actingAs($custodian)->getJson($url)->assertForbidden();

        $this->actingAs($this->admin())->getJson($url)->assertOk();
    }

    public function test_future_occurrences_are_projected_and_nothing_is_saved(): void
    {
        $s = $this->schedule();   // monthly from Nov 15

        $events = $this->events();

        $this->assertSame(['2026-11-15', '2026-12-15'], array_column($events, 'start'));
        $this->assertSame('projected', $events[0]['extendedProps']['kind']);
        $this->assertSame('upcoming', $events[0]['extendedProps']['status']);
        $this->assertSame('#2563eb', $events[0]['backgroundColor']);
        $this->assertSame('Aircon Cleaning', $events[0]['title']);

        $this->assertSame(0, MaintenanceRequest::count());
        $this->assertSame('2026-11-15', $s->fresh()->next_due_date->toDateString());
    }

    public function test_a_made_ticket_replaces_its_projection_and_is_never_shown_twice(): void
    {
        $s = $this->schedule();
        PmTicketGenerator::runDue('command');   // ticket for Nov 15, schedule moves to Dec 15

        $events = $this->events();

        $this->assertSame(['2026-11-15', '2026-12-15'], array_column($events, 'start'));
        $this->assertSame(['ticket', 'projected'], array_column(array_column($events, 'extendedProps'), 'kind'));
        $this->assertSame('open', $events[0]['extendedProps']['status']);
        $this->assertSame('#facc15', $events[0]['backgroundColor']);

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame('/tickets/'.$ticket->id, $events[0]['extendedProps']['ticket_url']);
        $this->assertSame('/admin/pm-schedules/'.$s->id.'/edit', $events[0]['extendedProps']['schedule_url']);

        // the job crashed after making the ticket: the schedule still points at Nov 15, but there is still ONE event that day
        PmSchedule::whereKey($s->id)->update(['next_due_date' => '2026-11-15']);

        $again = $this->events();
        $this->assertCount(2, $again);
        $this->assertSame(['2026-11-15', '2026-12-15'], array_column($again, 'start'));
    }

    public function test_overdue_and_completed_tickets_get_their_colours(): void
    {
        $this->schedule();
        PmTicketGenerator::runDue('command');   // ticket due Nov 15

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $first = $this->events()[0];
        $this->assertSame('overdue', $first['extendedProps']['status']);
        $this->assertSame('#dc2626', $first['backgroundColor']);

        MaintenanceRequest::query()->update(['status' => TicketStatus::WorkCompleted->value]);
        $first = $this->events()[0];
        $this->assertSame('completed', $first['extendedProps']['status']);
        $this->assertSame('#16a34a', $first['backgroundColor']);
    }

    public function test_filters_narrow_the_events(): void
    {
        $roomId = Facility::where('name', 'Conference Room')->value('id');
        $gymId = Facility::where('name', 'Gymnasium')->value('id');
        $aircon = Equipment::create(['name' => 'Aircon #1', 'facility_id' => $roomId]);

        $this->schedule(['title' => 'Aircon Cleaning', 'equipment_id' => $aircon->id]);
        $this->schedule(['title' => 'Generator Check', 'facility_id' => $gymId, 'start_date' => '2026-11-20']);

        $this->assertCount(4, $this->events());

        $this->assertSame(['Generator Check'], array_values(array_unique(array_column($this->events(['facility_id' => $gymId]), 'title'))));
        $this->assertSame(['Aircon Cleaning'], array_values(array_unique(array_column($this->events(['equipment_id' => $aircon->id]), 'title'))));

        $otherCategory = Category::where('name', '!=', 'Air Conditioning')->value('id');
        $this->assertCount(0, $this->events(['category_id' => $otherCategory]));

        $custodian = User::where('email', 'custodian@lcctanauan.edu.ph')->firstOrFail();
        $this->assertCount(0, $this->events(['assignee_id' => $custodian->id]));
        $this->assertCount(4, $this->events(['assignee_id' => $this->admin()->id]));

        $this->assertCount(4, $this->events(['status' => 'upcoming']));
        $this->assertCount(0, $this->events(['status' => 'overdue']));
    }

    public function test_inactive_and_ended_schedules(): void
    {
        $this->schedule(['title' => 'Paused', 'is_active' => false]);
        $this->schedule(['title' => 'Ends soon', 'end_date' => '2026-11-30']);

        $events = $this->events();

        $this->assertCount(1, $events);
        $this->assertSame('Ends soon', $events[0]['title']);
        $this->assertSame('2026-11-15', $events[0]['start']);
    }

    public function test_projection_stops_twelve_months_ahead(): void
    {
        $this->schedule();

        // today is Nov 8 2026, so the horizon is Nov 8 2027: Oct 15 2027 is in, Nov 15 2027 is out
        $events = $this->events(['start' => '2027-10-01', 'end' => '2027-12-31']);

        $this->assertSame(['2027-10-15'], array_column($events, 'start'));
    }

    public function test_a_bad_or_too_long_range_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('maintenance-calendar.events', ['start' => '2026-12-10', 'end' => '2026-12-01']))
            ->assertUnprocessable();

        $this->actingAs($admin)->getJson(route('maintenance-calendar.events', ['start' => '2026-01-01', 'end' => '2027-12-31']))
            ->assertUnprocessable();

        $this->actingAs($admin)->getJson(route('maintenance-calendar.events'))->assertUnprocessable();
    }
}