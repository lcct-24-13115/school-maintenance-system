<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\PmCompliance;
use App\Services\PmTicketGenerator;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PM E4: the whole life of a PM schedule in one place.
 * schedule -> nightly job makes the ticket -> accept -> Complete PM form -> next due date -> calendar -> compliance report.
 */
class PmEndToEndTest extends TestCase
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
        $schedule = PmSchedule::create(array_merge([
            'title' => 'Aircon Cleaning',
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'category_id' => Category::where('name', 'Air Conditioning')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->admin()->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'schedule_basis' => 'fixed',
            'is_active' => true,
            'created_by' => $this->admin()->id,
        ], $over));

        $schedule->checklistItems()->create(['label' => 'Clean the filter', 'sort_order' => 0]);
        $schedule->checklistItems()->create(['label' => 'Check the refrigerant', 'sort_order' => 1]);

        return $schedule;
    }

    /** The calendar feed for the given range, keyed by event id. @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function calendar(string $start = '2026-11-01', string $end = '2027-01-31')
    {
        $json = $this->actingAs($this->admin())
            ->getJson(route('maintenance-calendar.events', ['start' => $start, 'end' => $end]))
            ->assertOk()->json();

        return collect($json)->keyBy('id');
    }

    private function complete(MaintenanceRequest $ticket, string $date): void
    {
        $checklist = [];
        foreach ($ticket->pmChecklist as $item) {
            $checklist[$item->id] = ['done' => 1];
        }

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), [
            'completed_date' => $date,
            'technician_id' => $this->admin()->id,
            'notes' => 'All good.',
            'checklist' => $checklist,
        ])->assertSessionHasNoErrors()->assertSessionHas('status');
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return (new PmCompliance($this->admin()))->analyse()['summary'];
    }

    public function test_a_fixed_schedule_from_the_first_ticket_to_an_overdue_second_one(): void
    {
        $schedule = $this->schedule();

        // ---- before the lead time: nothing to make, but the calendar already shows the coming dates ----
        $this->travelTo(Carbon::parse('2026-11-01 01:00:00'));
        PmTicketGenerator::runDue('schedule', '2026-11-01');
        $this->assertSame(0, MaintenanceRequest::where('pm_schedule_id', $schedule->id)->count());
        $this->assertSame('upcoming', $this->calendar()->get('pm-'.$schedule->id.'-2026-11-15')['extendedProps']['status']);

        // ---- Nov 8: seven days before Nov 15, the nightly job makes the ticket ----
        $this->travelTo(Carbon::parse('2026-11-08 01:00:00'));
        PmTicketGenerator::runDue('schedule', '2026-11-08');

        $ticket = MaintenanceRequest::where('pm_schedule_id', $schedule->id)->firstOrFail();
        $this->assertSame('2026-11-15', $ticket->pm_due_date->toDateString());
        $this->assertSame('2026-12-15', $schedule->refresh()->next_due_date->toDateString());
        $this->assertCount(2, $ticket->pmChecklist);

        // running the job again the same day does not make a second ticket
        PmTicketGenerator::runDue('schedule', '2026-11-08');
        $this->assertSame(1, MaintenanceRequest::where('pm_schedule_id', $schedule->id)->count());

        // the ticket now stands on its date, and the coming date is no longer shown twice
        $events = $this->calendar();
        $this->assertSame('open', $events->get('ticket-'.$ticket->id)['extendedProps']['status']);
        $this->assertNull($events->get('pm-'.$schedule->id.'-2026-11-15'));
        $this->assertSame('upcoming', $events->get('pm-'.$schedule->id.'-2026-12-15')['extendedProps']['status']);

        // ---- the technician accepts the task and completes it on Nov 14, one day early ----
        TicketWorkflow::startWork($ticket->refresh(), $this->admin());
        $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
        $this->complete($ticket->refresh(), '2026-11-14');

        $ticket->refresh();
        $this->assertSame(TicketStatus::WorkCompleted, $ticket->status);
        $this->assertSame('2026-11-14', $ticket->pm_completed_date->toDateString());
        $this->assertSame(2, $ticket->pmChecklist()->where('is_done', true)->count());

        // fixed schedule: the rhythm does not move
        $schedule->refresh();
        $this->assertSame('2026-11-14', $schedule->last_completed_date->toDateString());
        $this->assertSame('2026-12-15', $schedule->next_due_date->toDateString());

        // calendar: green; report: 100 %
        $this->assertSame('completed', $this->calendar()->get('ticket-'.$ticket->id)['extendedProps']['status']);

        $s = $this->summary();
        $this->assertSame(1, $s['on_time']);
        $this->assertSame(0, $s['late']);
        $this->assertSame(0, $s['overdue']);
        $this->assertEquals(100.0, $s['rate']);

        // ---- Dec 8: the second ticket is made, and nobody works on it ----
        $this->travelTo(Carbon::parse('2026-12-08 01:00:00'));
        PmTicketGenerator::runDue('schedule', '2026-12-08');

        $second = MaintenanceRequest::where('pm_schedule_id', $schedule->id)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($ticket->id, $second->id);
        $this->assertSame('2026-12-15', $second->pm_due_date->toDateString());
        $this->assertSame('2027-01-15', $schedule->refresh()->next_due_date->toDateString());

        // ---- Dec 20: five days past the due date ----
        $this->travelTo(Carbon::parse('2026-12-20 09:00:00'));

        $this->assertSame('overdue', $this->calendar()->get('ticket-'.$second->id)['extendedProps']['status']);

        $s = $this->summary();
        $this->assertSame(1, $s['on_time']);
        $this->assertSame(1, $s['overdue']);
        $this->assertEquals(50.0, $s['rate']);

        $this->actingAs($this->admin())->get(route('pm-reports.index'))->assertOk()->assertSee('50.0%');
        $this->actingAs($this->admin())->get(route('maintenance-calendar.index', ['month' => '2026-12']))
            ->assertOk()
            ->assertViewHas('counts', fn ($c) => $c['overdue'] === 1 && $c['completed'] === 0);
    }

    public function test_work_done_after_the_due_date_is_late_in_the_report(): void
    {
        $schedule = $this->schedule();

        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');
        TicketWorkflow::startWork($ticket->refresh(), $this->admin());

        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->complete($ticket->refresh(), '2026-11-20');   // due Nov 15: five days late

        $s = $this->summary();
        $this->assertSame(0, $s['on_time']);
        $this->assertSame(1, $s['late']);
        $this->assertEquals(0.0, $s['rate']);
        $this->assertEquals(5.0, $s['avg_days_late']);

        $this->assertSame('completed', $this->calendar()->get('ticket-'.$ticket->id)['extendedProps']['status']);
    }

    public function test_a_floating_schedule_moves_its_coming_dates_to_the_day_the_work_was_done(): void
    {
        $schedule = $this->schedule(['schedule_basis' => 'floating']);

        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');
        TicketWorkflow::startWork($ticket->refresh(), $this->admin());

        $this->travelTo(Carbon::parse('2026-11-12 10:00:00'));
        $this->complete($ticket->refresh(), '2026-11-12');

        $schedule->refresh();
        $this->assertSame('2026-12-12', $schedule->next_due_date->toDateString());

        // the calendar follows: Dec 12 and Jan 12, and the old Dec 15 is gone
        $events = $this->calendar();
        $this->assertSame('upcoming', $events->get('pm-'.$schedule->id.'-2026-12-12')['extendedProps']['status']);
        $this->assertSame('upcoming', $events->get('pm-'.$schedule->id.'-2027-01-12')['extendedProps']['status']);
        $this->assertNull($events->get('pm-'.$schedule->id.'-2026-12-15'));
    }

    public function test_a_paused_schedule_keeps_its_tickets_on_the_calendar_but_projects_nothing(): void
    {
        $schedule = $this->schedule();
        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');

        $schedule->refresh()->update(['is_active' => false]);

        $events = $this->calendar();
        $this->assertNotNull($events->get('ticket-'.$ticket->id));
        $this->assertNull($events->get('pm-'.$schedule->id.'-2026-12-15'));
    }
}
