<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use App\Models\User;
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
 * Phase 9: request_info (Waiting for User + SLA pause), the requester's answer, and the tickets:autoclose command,
 * all tested with a fake clock (travelTo).
 */
class TicketInfoRequestTest extends TestCase
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

    /** A ticket in Waiting for User, asked by maintenance at the current (fake) time. */
    private function waiting(): MaintenanceRequest
    {
        $ticket = $this->inProgress();
        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');

        return $ticket->refresh();
    }

    private function act(string $route, MaintenanceRequest $ticket, string $who, array $data = [])
    {
        return $this->actingAs($this->user($who))->post(route($route, $ticket), $data);
    }

    private function lastLog(MaintenanceRequest $ticket)
    {
        return $ticket->statusLogs()->reorder('id', 'desc')->first();
    }

    // ---- request_info ---------------------------------------------------------------------------

    public function test_staff_ask_for_info_and_the_ticket_waits(): void
    {
        $ticket = $this->inProgress();

        $this->act('tickets.request-info', $ticket, 'maintenance', ['info_question' => 'Which faucet is it?'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->status);

        $log = $this->lastLog($ticket);
        $this->assertSame('in_progress', $log->from_status);
        $this->assertSame('waiting_for_user', $log->to_status);
        $this->assertSame('in_progress', $log->meta['waiting_from']);
        $this->assertStringContainsString('Which faucet is it?', $log->note);
        $this->assertSame($this->user('maintenance')->id, $log->user_id);
    }

    public function test_it_can_be_asked_from_every_open_status_but_not_twice_or_when_finished(): void
    {
        $submitted = $this->ticket();
        TicketWorkflow::requestInfo($submitted, $this->user('maintenance'), 'Which room?');
        $this->assertSame(TicketStatus::WaitingForUser, $submitted->fresh()->status);

        // already waiting
        $this->act('tickets.request-info', $submitted, 'maintenance', ['info_question' => 'Again?'])
            ->assertSessionHas('error');

        // finished work cannot be questioned
        $done = $this->inProgress();
        TicketWorkflow::markWorkCompleted($done, $this->user('maintenance'));
        $this->act('tickets.request-info', $done, 'maintenance', ['info_question' => 'Hello?'])
            ->assertSessionHas('error');
        $this->assertSame(TicketStatus::WorkCompleted, $done->fresh()->status);
    }

    public function test_only_staff_with_request_info_may_ask_and_a_question_is_required(): void
    {
        $ticket = $this->inProgress();

        $this->act('tickets.request-info', $ticket, 'requester', ['info_question' => 'x'])->assertForbidden();
        $this->act('tickets.request-info', $ticket, 'president', ['info_question' => 'x'])->assertForbidden();
        $this->act('tickets.request-info', $ticket, 'finance', ['info_question' => 'x'])->assertForbidden();

        $this->act('tickets.request-info', $ticket, 'maintenance', ['info_question' => ''])
            ->assertSessionHasErrorsIn('info', 'info_question');

        try {
            TicketWorkflow::requestInfo($ticket, $this->user('requester'), 'x');
            $this->fail('The workflow itself must refuse a requester.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only maintenance staff', $e->getMessage());
        }

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ---- the answer -----------------------------------------------------------------------------

    public function test_the_answer_sends_the_ticket_back_to_in_progress(): void
    {
        $ticket = $this->waiting();

        $this->act('tickets.respond-info', $ticket, 'requester', ['info_reply' => 'The one in the corner.'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);

        $log = $this->lastLog($ticket);
        $this->assertSame('waiting_for_user', $log->from_status);
        $this->assertSame('in_progress', $log->to_status);
        $this->assertStringContainsString('The one in the corner.', $log->note);
        $this->assertSame($this->user('requester')->id, $log->user_id);
    }

    public function test_the_answer_goes_back_to_where_the_ticket_was_not_always_in_progress(): void
    {
        // asked while still Submitted: the answer must NOT skip authorization by jumping to In Progress
        $ticket = $this->ticket();
        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which room?');

        $this->act('tickets.respond-info', $ticket, 'requester', ['info_reply' => 'Room 204'])->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    public function test_only_the_requester_may_answer_and_only_while_waiting(): void
    {
        $ticket = $this->waiting();

        $this->act('tickets.respond-info', $ticket, 'maintenance', ['info_reply' => 'x'])->assertForbidden();
        $this->act('tickets.respond-info', $ticket, 'president', ['info_reply' => 'x'])->assertForbidden();
        $this->act('tickets.respond-info', $ticket, 'requester', ['info_reply' => ''])
            ->assertSessionHasErrorsIn('reply', 'info_reply');
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);

        $notWaiting = $this->inProgress();
        $this->act('tickets.respond-info', $notWaiting, 'requester', ['info_reply' => 'Hello'])
            ->assertSessionHas('error');
        $this->assertSame(TicketStatus::InProgress, $notWaiting->fresh()->status);
    }

    // ---- SLA clock ------------------------------------------------------------------------------

    public function test_a_waiting_ticket_is_never_overdue(): void
    {
        $ticket = $this->inProgress();
        $ticket->forceFill(['due_at' => now()->subHour()])->saveQuietly();
        $this->assertSame(1, MaintenanceRequest::overdue()->count());

        TicketWorkflow::requestInfo($ticket->refresh(), $this->user('maintenance'), 'Which faucet?');

        $this->assertSame(0, MaintenanceRequest::overdue()->count());
        $this->assertFalse($ticket->fresh()->isOverdue());

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('SLA paused')->assertDontSee('Overdue by');
    }

    public function test_the_waited_time_is_added_to_the_target_date(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());

        $ticket = $this->inProgress();
        $ticket->forceFill(['due_at' => $t0->copy()->addHours(10)])->saveQuietly();
        $due = $ticket->fresh()->due_at->copy();

        TicketWorkflow::requestInfo($ticket->refresh(), $this->user('maintenance'), 'Which faucet?');

        $this->travelTo($t0->copy()->addDays(3));
        $this->assertEquals($due->timestamp, $ticket->fresh()->due_at->timestamp, 'the target date does not move while waiting');

        TicketWorkflow::respondToInfo($ticket->refresh(), $this->user('requester'), 'The corner one.');

        $this->assertEqualsWithDelta($due->copy()->addDays(3)->timestamp, $ticket->fresh()->due_at->timestamp, 1);
        $this->assertFalse($ticket->fresh()->isOverdue());
    }

    // ---- tickets:autoclose (fake clock) ---------------------------------------------------------

    public function test_autoclose_waits_for_seven_days_then_closes(): void
    {
        $this->assertSame(7, Setting::int('autoclose_waiting_days'));   // the default

        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();

        $this->travelTo($t0->copy()->addDays(7)->subSecond());
        $this->artisan('tickets:autoclose')->assertExitCode(0);
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status, '6 days 23:59:59 is not enough');

        $this->travelTo($t0->copy()->addDays(7));
        $this->artisan('tickets:autoclose')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Cancelled, $ticket->status);

        $log = $this->lastLog($ticket);
        $this->assertSame('waiting_for_user', $log->from_status);
        $this->assertSame('cancelled', $log->to_status);
        $this->assertNull($log->user_id);
        $this->assertTrue($log->meta['auto_closed']);
        $this->assertStringContainsString('Auto-closed', $log->note);
        $this->assertStringContainsString('7 days', $log->note);
    }

    public function test_the_number_of_days_comes_from_the_setting_and_zero_turns_it_off(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();

        Setting::put('autoclose_waiting_days', 2);
        $this->travelTo($t0->copy()->addDays(2));
        $this->artisan('tickets:autoclose')->assertExitCode(0);
        $this->assertSame(TicketStatus::Cancelled, $ticket->fresh()->status);

        $other = $this->waiting();
        Setting::put('autoclose_waiting_days', 0);
        $this->travelTo(now()->addDays(60));
        $this->artisan('tickets:autoclose')->expectsOutputToContain('turned off')->assertExitCode(0);
        $this->assertSame(TicketStatus::WaitingForUser, $other->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();
        $logs = $ticket->statusLogs()->count();

        $this->travelTo($t0->copy()->addDays(8));
        $this->artisan('tickets:autoclose --dry-run')->expectsOutputToContain('Would close')->assertExitCode(0);

        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);
        $this->assertSame($logs, $ticket->statusLogs()->count());
    }

    public function test_only_waiting_tickets_are_touched(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $working = $this->inProgress();
        $submitted = $this->ticket();

        $this->travelTo($t0->copy()->addDays(30));
        $this->artisan('tickets:autoclose')->assertExitCode(0);

        $this->assertSame(TicketStatus::InProgress, $working->fresh()->status);
        $this->assertSame(TicketStatus::Submitted, $submitted->fresh()->status);
    }

    public function test_an_answer_and_a_new_question_start_a_fresh_wait(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();

        $this->travelTo($t0->copy()->addDays(5));
        TicketWorkflow::respondToInfo($ticket->refresh(), $this->user('requester'), 'Answer one');
        TicketWorkflow::requestInfo($ticket->refresh(), $this->user('maintenance'), 'One more thing?');

        // 10 days after the FIRST question, but only 5 after the latest one
        $this->travelTo($t0->copy()->addDays(10));
        $this->artisan('tickets:autoclose')->assertExitCode(0);
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);

        $this->travelTo($t0->copy()->addDays(12));
        $this->artisan('tickets:autoclose')->assertExitCode(0);
        $this->assertSame(TicketStatus::Cancelled, $ticket->fresh()->status);
    }

    public function test_the_workflow_itself_refuses_an_early_or_wrong_auto_close(): void
    {
        $this->travelTo($t0 = now()->startOfSecond());
        $ticket = $this->waiting();

        try {
            TicketWorkflow::autoCloseWaiting($ticket, 7);
            $this->fail('Too early.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not waited', $e->getMessage());
        }

        $working = $this->inProgress();

        try {
            TicketWorkflow::autoCloseWaiting($working, 7);
            $this->fail('Not waiting.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not waiting', $e->getMessage());
        }
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'tickets:autoclose'));

        $this->assertNotNull($event, 'tickets:autoclose is not scheduled');
        $this->assertSame('0 * * * *', $event->expression);
    }

    // ---- settings + panel -----------------------------------------------------------------------

    public function test_the_admin_can_change_the_autoclose_days_and_old_forms_still_save(): void
    {
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 96, 'sla_hours_emergency' => 12, 'reopen_window_days' => 7, 'autoclose_waiting_days' => 3,
        ])->assertRedirect(route('admin.settings.edit'));
        $this->assertSame(3, Setting::int('autoclose_waiting_days'));

        // a form without the new field leaves it alone
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 96, 'sla_hours_emergency' => 12, 'reopen_window_days' => 7,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, Setting::int('autoclose_waiting_days'));

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 96, 'sla_hours_emergency' => 12, 'reopen_window_days' => 7, 'autoclose_waiting_days' => -1,
        ])->assertSessionHasErrors('autoclose_waiting_days');

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()->assertSee('autoclose_waiting_days');
    }

    public function test_the_panel_shows_the_right_form_to_the_right_person(): void
    {
        $ticket = $this->inProgress();

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee(route('tickets.request-info', $ticket))
            ->assertDontSee(route('tickets.respond-info', $ticket));
        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.request-info', $ticket))
            ->assertDontSee(route('tickets.respond-info', $ticket));

        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee(route('tickets.respond-info', $ticket))
            ->assertSee('Which faucet is it?')
            ->assertSee('closed automatically');
        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.request-info', $ticket))
            ->assertDontSee(route('tickets.respond-info', $ticket));
    }
}
