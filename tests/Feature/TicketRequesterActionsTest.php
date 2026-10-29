<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 8: the requester's own actions. Cancel (before Assigned), Confirm resolved (Work Completed -> Closed)
 * and Reopen (Work Completed / Closed, inside settings.reopen_window_days).
 */
class TicketRequesterActionsTest extends TestCase
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

    private function approved(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));

        return $ticket->refresh();
    }

    private function assigned(): MaintenanceRequest
    {
        $ticket = $this->approved();
        TicketWorkflow::assign($ticket, $this->user('maintenance'), $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function inProgress(): MaintenanceRequest
    {
        $ticket = $this->approved();
        TicketWorkflow::startWork($ticket, $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function workCompleted(): MaintenanceRequest
    {
        $ticket = $this->inProgress();
        TicketWorkflow::markWorkCompleted($ticket, $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function closed(): MaintenanceRequest
    {
        $ticket = $this->workCompleted();
        TicketWorkflow::close($ticket, $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function act(string $route, MaintenanceRequest $ticket, string $who, array $data = [])
    {
        return $this->actingAs($this->user($who))->post(route($route, $ticket), $data);
    }

    // ---- cancel: only before Assigned -----------------------------------------------------------

    public function test_the_requester_cancels_before_the_ticket_is_assigned(): void
    {
        foreach (['submitted', 'pending_authorization', 'approved'] as $start) {
            $ticket = match ($start) {
                'submitted' => $this->ticket(),
                'pending_authorization' => tap($this->ticket(), fn ($t) => TicketWorkflow::sendForAuthorization($t, $this->user('maintenance'))),
                'approved' => $this->approved(),
            };

            $this->act('tickets.cancel', $ticket, 'requester', ['cancel_reason' => 'Fixed it myself'])
                ->assertRedirect()->assertSessionHasNoErrors();

            $ticket->refresh();
            $this->assertSame(TicketStatus::Cancelled, $ticket->status, $start);

            $log = $ticket->statusLogs()->reorder('id', 'desc')->first();
            $this->assertSame($start, $log->from_status);
            $this->assertSame('cancelled', $log->to_status);
            $this->assertStringContainsString('Fixed it myself', $log->note);
        }
    }

    public function test_cancelling_from_waiting_for_user_only_works_if_it_was_never_assigned(): void
    {
        $never = $this->ticket();
        TicketWorkflow::transition($never, TicketStatus::WaitingForUser, $this->user('maintenance'));
        TicketWorkflow::cancel($never->refresh(), $this->user('requester'), 'No longer needed');
        $this->assertSame(TicketStatus::Cancelled, $never->fresh()->status);

        $wasAssigned = $this->assigned();
        TicketWorkflow::transition($wasAssigned, TicketStatus::WaitingForUser, $this->user('maintenance'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('can no longer be cancelled');
        TicketWorkflow::cancel($wasAssigned->refresh(), $this->user('requester'), 'Too late');
    }

    public function test_cancel_is_refused_once_the_ticket_is_assigned_or_later(): void
    {
        $cases = [
            'assigned' => $this->assigned(),
            'in_progress' => $this->inProgress(),
            'work_completed' => $this->workCompleted(),
            'closed' => $this->closed(),
        ];

        foreach ($cases as $status => $ticket) {
            $logs = $ticket->statusLogs()->count();

            $this->act('tickets.cancel', $ticket, 'requester', ['cancel_reason' => 'Changed my mind'])
                ->assertSessionHas('error');

            $this->assertSame($status, $ticket->fresh()->status->value);
            $this->assertSame($logs, $ticket->statusLogs()->count());
        }
    }

    public function test_cancel_needs_a_reason(): void
    {
        $ticket = $this->ticket();

        $this->act('tickets.cancel', $ticket, 'requester', ['cancel_reason' => ''])
            ->assertSessionHasErrorsIn('cancel', 'cancel_reason');

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);

        $this->expectException(DomainException::class);
        TicketWorkflow::cancel($ticket, $this->user('requester'), '  ');
    }

    public function test_only_the_requester_can_cancel(): void
    {
        $ticket = $this->ticket();

        // a user who can see the ticket but did not file it: 403
        $this->act('tickets.cancel', $ticket, 'maintenance', ['cancel_reason' => 'x'])->assertForbidden();
        $this->act('tickets.cancel', $ticket, 'finance', ['cancel_reason' => 'x'])->assertForbidden();

        try {
            TicketWorkflow::cancel($ticket, $this->user('maintenance'), 'x');
            $this->fail('The workflow itself must refuse a non-owner.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only the person who filed', $e->getMessage());
        }

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    // ---- confirm resolved -----------------------------------------------------------------------

    public function test_confirm_resolved_closes_a_work_completed_ticket_at_once(): void
    {
        $ticket = $this->workCompleted();

        $this->act('tickets.confirm-resolved', $ticket, 'requester')
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        $log = $ticket->statusLogs()->reorder('id', 'desc')->first();
        $this->assertSame('work_completed', $log->from_status);
        $this->assertSame('closed', $log->to_status);
        $this->assertSame($this->user('requester')->id, $log->user_id);
    }

    public function test_confirm_resolved_only_works_in_work_completed_and_only_for_the_requester(): void
    {
        $inProgress = $this->inProgress();
        $this->act('tickets.confirm-resolved', $inProgress, 'requester')->assertSessionHas('error');
        $this->assertSame(TicketStatus::InProgress, $inProgress->fresh()->status);

        $closed = $this->closed();
        $this->act('tickets.confirm-resolved', $closed, 'requester')->assertSessionHas('error');

        $done = $this->workCompleted();
        $this->act('tickets.confirm-resolved', $done, 'maintenance')->assertForbidden();
        $this->assertSame(TicketStatus::WorkCompleted, $done->fresh()->status);
    }

    // ---- reopen: status + window ----------------------------------------------------------------

    public function test_the_requester_reopens_a_work_completed_ticket_inside_the_window(): void
    {
        $ticket = $this->workCompleted();

        $this->act('tickets.reopen', $ticket, 'requester', ['reopen_reason' => 'Still leaking'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Reopened, $ticket->status);
        $this->assertNull($ticket->completed_at);
        $this->assertNull($ticket->closed_at);

        $log = $ticket->statusLogs()->reorder('id', 'desc')->first();
        $this->assertSame('work_completed', $log->from_status);
        $this->assertSame('reopened', $log->to_status);
        $this->assertSame('work_completed', $log->meta['reopened_from']);
        $this->assertStringContainsString('Still leaking', $log->note);
    }

    public function test_the_requester_reopens_a_closed_ticket_and_the_window_counts_from_closing(): void
    {
        Setting::put('reopen_window_days', 14);

        $ticket = $this->workCompleted();                       // completed today
        $this->travelTo($ticket->completed_at->copy()->addDays(10));
        TicketWorkflow::close($ticket, $this->user('maintenance'));   // closed on day 10

        // day 20 is 20 days after completion but only 10 after closing: still open
        $this->travelTo($ticket->fresh()->closed_at->copy()->addDays(10));
        $this->act('tickets.reopen', $ticket, 'requester', ['reopen_reason' => 'Back again'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::Reopened, $ticket->fresh()->status);
    }

    public function test_the_window_is_inclusive_and_ends_afterwards(): void
    {
        Setting::put('reopen_window_days', 14);
        $ticket = $this->workCompleted();
        $deadline = $ticket->completed_at->copy()->addDays(14);
        $requester = $this->user('requester');

        $this->travelTo($deadline->copy()->addSecond());
        $this->assertNotNull(TicketWorkflow::reopenBlocker($ticket->fresh(), $requester));
        $this->act('tickets.reopen', $ticket, 'requester', ['reopen_reason' => 'Too late'])
            ->assertSessionHas('error');
        $this->assertSame(TicketStatus::WorkCompleted, $ticket->fresh()->status);

        $this->travelTo($deadline);   // the last moment is still allowed
        $this->assertNull(TicketWorkflow::reopenBlocker($ticket->fresh(), $requester));
    }

    public function test_the_window_comes_from_the_setting(): void
    {
        $ticket = $this->workCompleted();
        $requester = $this->user('requester');

        Setting::put('reopen_window_days', 3);
        $this->travelTo($ticket->completed_at->copy()->addDays(4));
        $this->assertNotNull(TicketWorkflow::reopenBlocker($ticket->fresh(), $requester));

        Setting::put('reopen_window_days', 30);
        $this->assertNull(TicketWorkflow::reopenBlocker($ticket->fresh(), $requester));

        Setting::put('reopen_window_days', 0);   // 0 turns reopening off
        $this->travelBack();
        $this->assertStringContainsString('turned off', TicketWorkflow::reopenBlocker($ticket->fresh(), $requester));
    }

    public function test_reopen_is_refused_in_every_other_status(): void
    {
        $cases = [
            'submitted' => $this->ticket(),
            'approved' => $this->approved(),
            'assigned' => $this->assigned(),
            'in_progress' => $this->inProgress(),
        ];

        $cancelled = $this->ticket();
        TicketWorkflow::cancel($cancelled, $this->user('requester'), 'Not needed');
        $cases['cancelled'] = $cancelled->refresh();

        $already = $this->workCompleted();
        TicketWorkflow::reopen($already, $this->user('requester'), 'Again');
        $cases['reopened'] = $already->refresh();

        foreach ($cases as $status => $ticket) {
            $logs = $ticket->statusLogs()->count();

            $this->act('tickets.reopen', $ticket, 'requester', ['reopen_reason' => 'Please'])
                ->assertSessionHas('error');

            $this->assertSame($status, $ticket->fresh()->status->value);
            $this->assertSame($logs, $ticket->statusLogs()->count());
        }
    }

    public function test_reopen_needs_a_reason_and_only_the_requester_may_do_it(): void
    {
        $ticket = $this->workCompleted();

        $this->act('tickets.reopen', $ticket, 'requester', ['reopen_reason' => ''])
            ->assertSessionHasErrorsIn('reopen', 'reopen_reason');
        $this->act('tickets.reopen', $ticket, 'maintenance', ['reopen_reason' => 'x'])->assertForbidden();
        $this->act('tickets.reopen', $ticket, 'president', ['reopen_reason' => 'x'])->assertForbidden();

        $this->assertSame(TicketStatus::WorkCompleted, $ticket->fresh()->status);
    }

    public function test_staff_can_take_a_reopened_ticket_back_into_work(): void
    {
        $ticket = $this->workCompleted();
        TicketWorkflow::reopen($ticket, $this->user('requester'), 'Still leaking');

        $this->actingAs($this->user('maintenance'))
            ->get(route('maintenance.board'))->assertOk();

        TicketWorkflow::startWork($ticket->refresh(), $this->user('maintenance'));
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ---- the Action panel -----------------------------------------------------------------------

    public function test_the_panel_offers_cancel_only_to_the_requester_and_only_before_assignment(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee(route('tickets.cancel', $ticket))
            ->assertDontSee(route('tickets.reopen', $ticket))
            ->assertDontSee(route('tickets.confirm-resolved', $ticket));

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.cancel', $ticket));

        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::assign($ticket->refresh(), $this->user('maintenance'), $this->user('maintenance'));

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.cancel', $ticket));
    }

    public function test_the_panel_of_a_work_completed_ticket_offers_confirm_and_reopen(): void
    {
        $ticket = $this->workCompleted();

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee(route('tickets.confirm-resolved', $ticket))
            ->assertSee(route('tickets.reopen', $ticket))
            ->assertDontSee(route('tickets.cancel', $ticket));

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.confirm-resolved', $ticket))
            ->assertDontSee(route('tickets.reopen', $ticket));
    }

    public function test_a_closed_ticket_offers_reopen_only_inside_the_window(): void
    {
        $ticket = $this->closed();
        $page = fn () => $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk();

        $page()->assertSee(route('tickets.reopen', $ticket))
            ->assertDontSee(route('tickets.confirm-resolved', $ticket))
            ->assertDontSee('No further actions are possible');

        $this->travelTo($ticket->fresh()->closed_at->copy()->addDays(Setting::int('reopen_window_days'))->addMinute());

        $page()->assertDontSee(route('tickets.reopen', $ticket))
            ->assertSee('No further actions are possible')
            ->assertSee('reopen window has ended');

        $this->assertSame([], TicketActions::for($ticket->fresh(), $this->user('maintenance')));
    }
}
