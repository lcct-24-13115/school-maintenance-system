<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
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

/** PM integration Phase B: completing a PM ticket (date, technician, notes, checklist) and the fixed / floating next due date. */
class PmCompletionTest extends TestCase
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

    /** A PM ticket that is In Progress; the clock is then moved to Nov 12. */
    private function inProgressTicket(PmSchedule $schedule): MaintenanceRequest
    {
        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');
        TicketWorkflow::startWork($ticket->refresh(), $this->admin());

        $this->travelTo(Carbon::parse('2026-11-12 10:00:00'));

        return $ticket->refresh();
    }

    private function form(MaintenanceRequest $ticket, array $over = []): array
    {
        $checklist = [];
        foreach ($ticket->pmChecklist as $item) {
            $checklist[$item->id] = ['done' => 1];
        }

        return array_merge([
            'completed_date' => '2026-11-12',
            'technician_id' => $this->admin()->id,
            'notes' => 'All good.',
            'checklist' => $checklist,
        ], $over);
    }

    public function test_completing_records_date_technician_notes_and_checklist(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket))
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::WorkCompleted, $ticket->status);
        $this->assertSame('2026-11-12', $ticket->pm_completed_date->toDateString());
        $this->assertSame($this->admin()->id, (int) $ticket->pm_technician_id);
        $this->assertSame('All good.', $ticket->pm_notes);
        $this->assertSame(2, $ticket->pmChecklist()->where('is_done', true)->count());
        $this->assertSame('2026-11-12', $ticket->pmSchedule->last_completed_date->toDateString());
    }

    public function test_fixed_schedule_keeps_its_original_next_due_date(): void
    {
        $schedule = $this->schedule(['schedule_basis' => 'fixed']);
        $ticket = $this->inProgressTicket($schedule);

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket));

        $this->assertSame('2026-12-15', $schedule->refresh()->next_due_date->toDateString());
        $this->assertNull($schedule->anchor_date);
    }

    public function test_floating_schedule_counts_the_next_due_date_from_the_completed_day(): void
    {
        $schedule = $this->schedule(['schedule_basis' => 'floating']);
        $ticket = $this->inProgressTicket($schedule);

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket));

        $schedule->refresh();
        $this->assertSame('2026-11-12', $schedule->anchor_date->toDateString());
        $this->assertSame('2026-12-12', $schedule->next_due_date->toDateString());
        $this->assertSame('2027-01-12', $schedule->occurrence(2)->toDateString());   // later dates follow the same anchor
    }

    public function test_floating_is_skipped_when_a_newer_ticket_already_exists(): void
    {
        $schedule = $this->schedule(['schedule_basis' => 'floating']);
        $ticket = $this->inProgressTicket($schedule);

        PmTicketGenerator::generate($schedule->refresh(), '2026-11-12', true);   // the ticket for Dec 15 already exists
        $before = $schedule->refresh()->next_due_date->toDateString();

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket));

        $schedule->refresh();
        $this->assertSame($before, $schedule->next_due_date->toDateString());
        $this->assertNull($schedule->anchor_date);
    }

    public function test_editing_the_schedule_dates_drops_the_floating_anchor(): void
    {
        $schedule = $this->schedule(['schedule_basis' => 'floating']);
        $ticket = $this->inProgressTicket($schedule);
        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket));

        $schedule->refresh()->update(['start_date' => '2026-12-01']);

        $this->assertNull($schedule->refresh()->anchor_date);
    }

    public function test_an_unticked_task_needs_a_note(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());
        $items = $ticket->pmChecklist;

        $payload = $this->form($ticket, ['checklist' => [
            $items[0]->id => ['done' => 1],
            $items[1]->id => ['note' => ''],
        ]]);

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $payload)->assertSessionHas('pm_error');
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);

        // with a reason it is accepted, and the reason feeds "problems encountered"
        $payload['checklist'][$items[1]->id] = ['note' => 'Refrigerant tank is empty'];
        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $payload)->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::WorkCompleted, $ticket->status);
        $this->assertStringContainsString('Check the refrigerant', (string) $ticket->problems_encountered);
    }

    public function test_the_completed_date_cannot_be_in_the_future_or_before_the_ticket_existed(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket, ['completed_date' => '2026-11-20']))
            ->assertSessionHasErrors('completed_date', errorBag: 'pmcomplete');

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket, ['completed_date' => '2026-11-01']))
            ->assertSessionHas('pm_error');

        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_the_ticket_must_be_in_progress(): void
    {
        $schedule = $this->schedule();
        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');   // Assigned, not accepted yet

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket->refresh()), $this->form($ticket, ['completed_date' => '2026-11-08']))
            ->assertSessionHas('pm_error');

        $this->assertNull($ticket->refresh()->pm_completed_date);
    }

    public function test_the_board_button_does_not_complete_a_pm_ticket(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());

        $this->actingAs($this->admin())->post(route('maintenance.complete', $ticket))->assertSessionHas('pm_error');

        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_people_without_the_work_capability_cannot_complete(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());
        $finance = User::where('email', 'finance@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($finance)->post(route('tickets.pm-complete', $ticket), $this->form($ticket))->assertForbidden();

        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_the_ticket_page_shows_the_form_while_in_progress_and_the_result_after(): void
    {
        $ticket = $this->inProgressTicket($this->schedule());

        $this->actingAs($this->admin())->get(route('tickets.show', $ticket))
            ->assertOk()->assertSee('Complete this PM')->assertSee('Clean the filter');

        $this->actingAs($this->admin())->post(route('tickets.pm-complete', $ticket), $this->form($ticket));

        $this->actingAs($this->admin())->get(route('tickets.show', $ticket))
            ->assertOk()->assertDontSee('Complete this PM')->assertSee('Completed on')->assertSee('All good.');
    }
}