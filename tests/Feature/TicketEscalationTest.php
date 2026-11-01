<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 12: the tickets:escalate command (past due_at, not paused, not finished, not twice), the manual Escalate
 * button (escalate capability) and the alerts, all tested with a fake clock (travelTo).
 */
class TicketEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
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

    private function inProgress(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::startWork($ticket->refresh(), $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function waiting(): MaintenanceRequest
    {
        $ticket = $this->inProgress();
        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');

        return $ticket->refresh();
    }

    /** Put a ticket in any status directly in the database (no model events), for the "which statuses qualify" tests. */
    private function force(MaintenanceRequest $ticket, TicketStatus $status): MaintenanceRequest
    {
        MaintenanceRequest::query()->whereKey($ticket->getKey())->update(['status' => $status->value]);

        return $ticket->refresh();
    }

    /** One minute after the ticket's target date. */
    private function pastDue(MaintenanceRequest $ticket): void
    {
        $this->travelTo($ticket->due_at->copy()->addMinute());
    }

    private function act(string $route, MaintenanceRequest $ticket, string $who, array $data = [])
    {
        return $this->actingAs($this->user($who))->post(route($route, $ticket), $data);
    }

    private function lastLog(MaintenanceRequest $ticket)
    {
        return $ticket->statusLogs()->reorder('id', 'desc')->first();
    }

    private function alerts(string $who)
    {
        return $this->user($who)->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'ticket_escalated');
    }

    // ---- tickets:escalate -----------------------------------------------------------------------

    public function test_a_ticket_past_its_target_date_is_escalated_by_the_command(): void
    {
        $ticket = $this->ticket();
        $this->pastDue($ticket);

        $this->artisan('tickets:escalate')->assertExitCode(0);

        $this->assertSame(TicketStatus::Escalated, $ticket->fresh()->status);

        $log = $this->lastLog($ticket);
        $this->assertSame('submitted', $log->from_status);
        $this->assertSame('escalated', $log->to_status);
        $this->assertNull($log->user_id);
        $this->assertTrue($log->meta['auto_escalated']);
        $this->assertSame('submitted', $log->meta['escalated_from']);
        $this->assertStringContainsString('Auto-escalated', $log->note);
    }

    public function test_a_ticket_that_is_not_yet_due_is_left_alone(): void
    {
        $ticket = $this->ticket();

        $this->travelTo($ticket->due_at->copy()->subMinute());
        $this->artisan('tickets:escalate')->assertExitCode(0);
        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);

        // exactly on the target date it is not overdue yet either (the overdue rule is "due_at < now")
        $this->travelTo($ticket->due_at->copy());
        $this->artisan('tickets:escalate')->assertExitCode(0);
        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    public function test_every_open_status_that_is_not_paused_is_escalated(): void
    {
        $statuses = TicketWorkflow::escalatableStatuses();

        $this->assertContains(TicketStatus::Submitted, $statuses);
        $this->assertContains(TicketStatus::InProgress, $statuses);
        $this->assertNotContains(TicketStatus::WaitingForUser, $statuses);
        $this->assertNotContains(TicketStatus::Escalated, $statuses);

        $tickets = [];
        foreach ($statuses as $status) {
            $tickets[$status->value] = $this->force($this->ticket(), $status);
        }

        $this->travelTo(now()->addDays(30));   // far past every target date

        $this->artisan('tickets:escalate')->assertExitCode(0);

        foreach ($tickets as $from => $ticket) {
            $this->assertSame(TicketStatus::Escalated, $ticket->fresh()->status, $from);
            $this->assertSame($from, $this->lastLog($ticket)->meta['escalated_from']);
        }
    }

    public function test_paused_and_finished_tickets_are_never_escalated(): void
    {
        $tickets = [];
        foreach ([
            TicketStatus::WaitingForUser, TicketStatus::WorkCompleted, TicketStatus::Closed,
            TicketStatus::Disapproved, TicketStatus::Cancelled,
        ] as $status) {
            $tickets[$status->value] = $this->force($this->ticket(), $status);
        }

        $logs = collect($tickets)->map(fn ($t) => $t->statusLogs()->count());

        $this->travelTo(now()->addDays(60));
        $this->artisan('tickets:escalate')->assertExitCode(0);

        foreach ($tickets as $status => $ticket) {
            $this->assertSame(TicketStatus::from($status), $ticket->fresh()->status, $status);
            $this->assertSame($logs[$status], $ticket->statusLogs()->count(), $status);
        }
    }

    public function test_a_waiting_ticket_is_not_escalated_and_gets_a_new_target_date_after_the_answer(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();
        $oldDue = $ticket->due_at->copy();

        // 10 days of waiting: far past the old target date, but the clock is paused
        $this->travelTo($t0->copy()->addDays(10));
        $this->artisan('tickets:escalate')->assertExitCode(0);
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);

        // the answer resumes the clock: the target date moved later by the time waited
        TicketWorkflow::respondToInfo($ticket->refresh(), $this->user('requester'), 'The kitchen one');
        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertEqualsWithDelta($oldDue->copy()->addDays(10)->timestamp, $ticket->due_at->timestamp, 1);

        $this->artisan('tickets:escalate')->assertExitCode(0);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status, 'not due again yet');

        // ...and only after the NEW target date does it escalate
        $this->pastDue($ticket);
        $this->artisan('tickets:escalate')->assertExitCode(0);
        $this->assertSame(TicketStatus::Escalated, $ticket->fresh()->status);
    }

    public function test_an_escalated_ticket_is_not_escalated_or_alerted_a_second_time(): void
    {
        $ticket = $this->inProgress();
        $this->pastDue($ticket);

        $this->artisan('tickets:escalate')->assertExitCode(0);
        $logs = $ticket->statusLogs()->count();
        $this->assertCount(1, $this->alerts('president'));

        $this->travelTo(now()->addDays(3));
        $this->artisan('tickets:escalate')->assertExitCode(0);

        $this->assertSame(TicketStatus::Escalated, $ticket->fresh()->status);
        $this->assertSame($logs, $ticket->statusLogs()->count());
        $this->assertCount(1, $this->alerts('president'));
    }

    public function test_the_president_and_every_maintenance_admin_are_alerted_but_nobody_else(): void
    {
        $ticket = $this->inProgress();
        $this->pastDue($ticket);

        $this->artisan('tickets:escalate')->assertExitCode(0);

        foreach (['president', 'maintenance', 'maintenance2', 'maintenance3'] as $who) {
            $this->assertCount(1, $this->alerts($who), $who);
        }

        foreach (['requester', 'finance', 'custodian', 'depthead', 'treasurer'] as $who) {
            $this->assertCount(0, $this->alerts($who), $who);
        }

        $alert = $this->alerts('president')->first()->data;
        $this->assertStringContainsString('System', $alert['title']);
        $this->assertSame($ticket->ticket_no, $alert['reference_no']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $ticket = $this->ticket();
        $logs = $ticket->statusLogs()->count();
        $this->pastDue($ticket);

        $this->artisan('tickets:escalate --dry-run')->expectsOutputToContain('Would escalate')->assertExitCode(0);

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
        $this->assertSame($logs, $ticket->statusLogs()->count());
        $this->assertCount(0, $this->alerts('president'));
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'tickets:escalate'));

        $this->assertNotNull($event, 'tickets:escalate is not scheduled');
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_the_workflow_refuses_an_early_or_wrong_auto_escalation(): void
    {
        $ticket = $this->ticket();

        try {
            TicketWorkflow::autoEscalate($ticket);
            $this->fail('Not due yet.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not past its target date', $e->getMessage());
        }

        $this->pastDue($ticket);
        $waiting = $this->force($ticket, TicketStatus::WaitingForUser);

        try {
            TicketWorkflow::autoEscalate($waiting);
            $this->fail('Waiting for User is paused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('waiting for the requester', $e->getMessage());
        }
    }

    // ---- the manual button ----------------------------------------------------------------------

    public function test_staff_escalate_by_hand_and_the_others_are_alerted(): void
    {
        $ticket = $this->inProgress();

        $this->act('tickets.escalate', $ticket, 'maintenance', ['escalate_reason' => 'Needs a budget decision'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Escalated, $ticket->status);

        $log = $this->lastLog($ticket);
        $this->assertSame('in_progress', $log->from_status);
        $this->assertSame('escalated', $log->to_status);
        $this->assertSame($this->user('maintenance')->id, $log->user_id);
        $this->assertSame('in_progress', $log->meta['escalated_from']);
        $this->assertArrayNotHasKey('auto_escalated', $log->meta);
        $this->assertStringContainsString('Needs a budget decision', $log->note);

        $this->assertCount(1, $this->alerts('president'));
        $this->assertCount(1, $this->alerts('maintenance2'));
        $this->assertCount(0, $this->alerts('maintenance'), 'not the person who escalated');
        $this->assertCount(0, $this->alerts('requester'));
    }

    public function test_a_reason_is_required(): void
    {
        $ticket = $this->inProgress();

        $this->act('tickets.escalate', $ticket, 'maintenance', ['escalate_reason' => ''])
            ->assertSessionHasErrorsIn('escalate', 'escalate_reason');

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_only_staff_with_the_escalate_capability_may_escalate(): void
    {
        $ticket = $this->inProgress();

        foreach (['requester', 'president', 'finance'] as $who) {
            $this->act('tickets.escalate', $ticket, $who, ['escalate_reason' => 'Please'])->assertForbidden();
        }

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        $this->expectException(DomainException::class);
        TicketWorkflow::escalate($ticket, $this->user('requester'), 'Direct call');
    }

    public function test_it_cannot_be_escalated_by_hand_when_paused_finished_or_already_escalated(): void
    {
        $waiting = $this->waiting();
        $this->act('tickets.escalate', $waiting, 'maintenance', ['escalate_reason' => 'Now'])->assertSessionHas('error');
        $this->assertSame(TicketStatus::WaitingForUser, $waiting->fresh()->status);

        $done = $this->inProgress();
        TicketWorkflow::markWorkCompleted($done, $this->user('maintenance'));
        $this->act('tickets.escalate', $done, 'maintenance', ['escalate_reason' => 'Now'])->assertSessionHas('error');
        $this->assertSame(TicketStatus::WorkCompleted, $done->fresh()->status);

        $up = $this->inProgress();
        TicketWorkflow::escalate($up, $this->user('maintenance'), 'First time');
        $logs = $up->statusLogs()->count();
        $this->act('tickets.escalate', $up, 'maintenance', ['escalate_reason' => 'Second time'])->assertSessionHas('error');
        $this->assertSame($logs, $up->statusLogs()->count());
    }

    // ---- the page -------------------------------------------------------------------------------

    public function test_the_escalate_button_shows_only_when_it_can_be_used(): void
    {
        $ticket = $this->inProgress();
        $maintenance = $this->user('maintenance');

        $this->assertContains(TicketActions::ESCALATE, TicketActions::for($ticket, $maintenance));
        $this->assertNotContains(TicketActions::ESCALATE, TicketActions::for($ticket, $this->user('requester')));
        $this->assertNotContains(TicketActions::ESCALATE, TicketActions::for($ticket, $this->user('president')));

        $this->actingAs($maintenance)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Escalate ticket');
        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.escalate', $ticket));

        // paused or already escalated: no button
        $waiting = $this->waiting();
        $this->assertNotContains(TicketActions::ESCALATE, TicketActions::for($waiting, $maintenance));

        TicketWorkflow::escalate($ticket, $maintenance, 'Budget');
        $this->assertNotContains(TicketActions::ESCALATE, TicketActions::for($ticket->fresh(), $maintenance));
    }
}
