<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 1: unified ticket foundation (numbers, statuses, transitions, queue, emergency, history). */
class TicketFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function data(array $overrides = []): array
    {
        $requester = $this->user('requester@lcctanauan.edu.ph');

        return $overrides + [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ];
    }

    /** A ticket created straight through the model (used where we need exact submission times). */
    private function ticketAt($submittedAt): MaintenanceRequest
    {
        $requester = $this->user('requester@lcctanauan.edu.ph');

        return MaintenanceRequest::create([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
            'submitted_at' => $submittedAt,
        ]);
    }

    public function test_ticket_numbers_use_the_mt_year_format_and_increase(): void
    {
        $this->seedBase();
        $requester = $this->user('requester@lcctanauan.edu.ph');

        $a = TicketWorkflow::open($requester, $this->data());
        $b = TicketWorkflow::open($requester, $this->data());

        $year = now()->year;
        $this->assertSame("MT-{$year}-00001", $a->ticket_no);
        $this->assertSame("MT-{$year}-00002", $b->ticket_no);
        $this->assertSame(TicketStatus::Submitted, $a->status);
        $this->assertNotNull($a->submitted_at);
    }

    public function test_filing_a_ticket_writes_the_first_history_line(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id,
            'action' => RequestStatusLog::ACTION_STATUS,
            'to_status' => 'submitted',
        ]);
    }

    public function test_an_invalid_transition_is_refused_with_a_clear_message(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Allowed next status');

        TicketWorkflow::transition($ticket, TicketStatus::Closed, $this->user('maintenance@lcctanauan.edu.ph'));
    }

    public function test_a_status_cannot_be_skipped_even_by_updating_the_model_directly(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot move from "Submitted" to "In Progress"');

        $ticket->update(['status' => TicketStatus::InProgress]);
    }

    public function test_closed_disapproved_and_cancelled_are_final(): void
    {
        // Phase 7: Closed can only go to Reopened (reopen action, Phase 10); Disapproved and Cancelled go nowhere.
        $this->assertSame([TicketStatus::Reopened], TicketStatus::Closed->allowedNext());
        $this->assertSame([], TicketStatus::Disapproved->allowedNext());
        $this->assertSame([], TicketStatus::Cancelled->allowedNext());

        foreach ([TicketStatus::Closed, TicketStatus::Disapproved, TicketStatus::Cancelled] as $status) {
            $this->assertTrue($status->isTerminal(), $status->value);
        }
        foreach ([TicketStatus::InProgress, TicketStatus::WorkCompleted, TicketStatus::WaitingForUser, TicketStatus::Escalated, TicketStatus::Reopened] as $status) {
            $this->assertFalse($status->isTerminal(), $status->value);
        }
    }

    public function test_a_ticket_cannot_be_closed_without_authorization(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        // approved WITHOUT the president's recorded authorization
        TicketWorkflow::transition($ticket, TicketStatus::PendingAuthorization, $staff);
        TicketWorkflow::transition($ticket, TicketStatus::Approved, $staff);
        TicketWorkflow::startWork($ticket, $staff);
        TicketWorkflow::markWorkCompleted($ticket, $staff);

        try {
            TicketWorkflow::close($ticket, $staff);
            $this->fail('Closing should have been refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('without recorded authorization', $e->getMessage());
        }

        $this->assertSame(TicketStatus::WorkCompleted, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->closed_at);
    }

    public function test_full_happy_path_records_every_step_in_the_history(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        TicketWorkflow::authorize($ticket, $this->user('president@lcctanauan.edu.ph'), 'OK');
        TicketWorkflow::startWork($ticket, $staff);                  // assignment + in progress
        TicketWorkflow::markWorkCompleted($ticket, $staff, 'Part was out of stock', 'Keep spares');
        TicketWorkflow::close($ticket, $staff);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertEquals($staff->id, $ticket->assigned_to);
        $this->assertNotNull($ticket->assigned_at);
        $this->assertNotNull($ticket->completed_at);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame('Part was out of stock', $ticket->problems_encountered);

        // submitted, authorization, assignment, in progress, work completed, closed
        $this->assertSame(6, $ticket->statusLogs()->count());
        $this->assertSame(
            ['status_change', 'authorization', 'assignment', 'status_change', 'status_change', 'status_change'],
            $ticket->statusLogs()->pluck('action')->all()
        );
    }

    public function test_only_the_president_can_authorize(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only the School President');

        TicketWorkflow::authorize($ticket, $this->user('maintenance@lcctanauan.edu.ph'));
    }

    public function test_a_ticket_waiting_for_authorization_cannot_be_accepted(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('needs authorization');

        TicketWorkflow::startWork($ticket, $this->user('maintenance@lcctanauan.edu.ph'));
    }

    public function test_reassignment_needs_a_note_and_is_logged(): void
    {
        $this->seedBase();
        $first = $this->user('maintenance@lcctanauan.edu.ph');
        $second = $this->user('maintenance2@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());
        TicketWorkflow::authorize($ticket, $this->user('president@lcctanauan.edu.ph'));

        TicketWorkflow::assign($ticket, $first, $first);
        $this->assertSame(TicketStatus::Assigned, $ticket->status);

        try {
            TicketWorkflow::assign($ticket, $second, $first);
            $this->fail('A reassignment without a note should be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('needs a note', $e->getMessage());
        }

        TicketWorkflow::assign($ticket, $second, $first, 'First staff is on leave.');

        $this->assertEquals($second->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('request_status_logs', ['request_id' => $ticket->id, 'action' => 'assignment']);
        $this->assertDatabaseHas('request_status_logs', ['request_id' => $ticket->id, 'action' => 'reassignment']);
    }

    public function test_an_emergency_flag_needs_a_reason(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('needs a reason');

        TicketWorkflow::flagEmergency($ticket, $this->user('maintenance@lcctanauan.edu.ph'), '   ');
    }

    public function test_emergency_goes_first_and_routine_order_stays_by_submission_time(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');

        $a = $this->ticketAt(now()->subHours(3));
        $b = $this->ticketAt(now()->subHours(2));
        $c = $this->ticketAt(now()->subHour());

        $this->assertSame([$a->id, $b->id, $c->id], MaintenanceRequest::queueOrder()->pluck('id')->all());

        TicketWorkflow::flagEmergency($c, $staff, 'Water leak in the corridor');
        $this->assertSame([$c->id, $a->id, $b->id], MaintenanceRequest::queueOrder()->pluck('id')->all());
        $this->assertSame([$a->id, $b->id], MaintenanceRequest::routineQueue()->pluck('id')->all());
        $this->assertSame('emergency', $c->fresh()->priority);

        // editing an older ticket must not change the order
        $a->update(['description' => 'Edited later']);
        $this->assertSame([$c->id, $a->id, $b->id], MaintenanceRequest::queueOrder()->pluck('id')->all());

        // removing the flag puts it back in its ORIGINAL place
        $submittedAt = $c->fresh()->submitted_at;
        TicketWorkflow::clearEmergency($c, $staff);
        $this->assertSame([$a->id, $b->id, $c->id], MaintenanceRequest::queueOrder()->pluck('id')->all());
        $this->assertTrue($submittedAt->equalTo($c->fresh()->submitted_at));
        $this->assertSame('first_come_first_served', $c->fresh()->priority);
        $this->assertNull($c->fresh()->emergency_reason);
    }

    public function test_category_must_match_the_request_type_when_a_type_is_given(): void
    {
        $this->seedBase();
        $equipment = Category::where('type', Category::TYPE_EQUIPMENT)->firstOrFail();
        $requester = $this->user('requester@lcctanauan.edu.ph');

        $this->expectException(DomainException::class);

        MaintenanceRequest::create([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => $equipment->id,
            'request_type' => 'materials',
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Mismatch',
        ]);
    }

    public function test_pending_and_completed_scopes_follow_the_proposal(): void
    {
        // Phase 7: Waiting for User, Escalated and Reopened are still open work. Cancelled is not.
        $this->assertEqualsCanonicalizing(
            ['submitted', 'pending_authorization', 'approved', 'assigned', 'in_progress', 'waiting_for_user', 'escalated', 'reopened'],
            TicketStatus::pendingValues()
        );
        $this->assertEqualsCanonicalizing(['work_completed', 'closed'], TicketStatus::completedValues());
    }

    // ---- the repair form (FO-08) now files a ticket ---------------------------------------------

    public function test_the_repair_form_files_a_ticket_in_the_unified_table(): void
    {
        $this->seedBase();
        $requester = $this->user('requester@lcctanauan.edu.ph');

        $response = $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'job_description' => 'Broken door lock',
            'reason' => 'Students cannot lock the room.',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('requests', 1);
        $this->assertDatabaseCount('repair_requests', 0);

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertMatchesRegularExpression('/^MT-\d{4}-\d{5}$/', $ticket->ticket_no);
        $this->assertEquals($requester->id, $ticket->requester_id);
        $this->assertSame('Broken door lock', $ticket->description);
        $this->assertSame(TicketStatus::Submitted, $ticket->status);
        $this->assertFalse($ticket->is_emergency);
    }

    // Phase 2 changed this rule ON PURPOSE: only users with flag_emergency can set the flag from the form
    // (before, anyone could). A requester asking for an emergency is covered in TicketAccessTest.
    public function test_an_emergency_repair_form_flags_the_ticket_with_a_reason(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');

        $this->actingAs($staff)->post(route('repairs.store'), [
            'department_id' => $staff->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Comfort Room',
            'job_description' => 'Pipe burst',
            'reason' => 'Water is flooding the floor.',
            'priority' => 'emergency',
        ])->assertRedirect(route('dashboard'));

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertTrue($ticket->is_emergency);
        $this->assertSame('Water is flooding the floor.', $ticket->emergency_reason);
        $this->assertEquals($staff->id, $ticket->emergency_flagged_by);
    }

    public function test_the_repair_form_requires_a_category(): void
    {
        $this->seedBase();
        $requester = $this->user('requester@lcctanauan.edu.ph');

        $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'location' => 'Library',
            'job_description' => 'Something',
            'reason' => 'Because',
            'priority' => 'emergency',
        ])->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('requests', 0);
    }

    // ---- maintenance board ---------------------------------------------------------------------

    public function test_the_board_accepts_and_completes_an_approved_ticket(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());
        TicketWorkflow::authorize($ticket, $this->user('president@lcctanauan.edu.ph'));

        $this->actingAs($staff)->post(route('maintenance.accept', $ticket))->assertSessionHas('status');
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertEquals($staff->id, $ticket->fresh()->assigned_to);

        $this->actingAs($staff)->post(route('maintenance.complete', $ticket))->assertSessionHas('status');
        $this->assertSame(TicketStatus::WorkCompleted, $ticket->fresh()->status);
    }

    public function test_the_board_refuses_a_ticket_that_is_still_waiting_for_authorization(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->actingAs($this->user('maintenance@lcctanauan.edu.ph'))
            ->post(route('maintenance.accept', $ticket))
            ->assertSessionHasErrors('board');

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    public function test_a_requester_cannot_use_the_board_actions(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        $this->actingAs($this->user('requester@lcctanauan.edu.ph'))
            ->post(route('maintenance.accept', $ticket))
            ->assertForbidden();
    }

    // ---- Phase 7: waiting_for_user, escalated, cancelled, reopened ------------------------------

    /** The whole move table, written out. Change TicketStatus::allowedNext() and this test must change on purpose. */
    private const MOVES = [
        'submitted' => ['pending_authorization', 'approved', 'disapproved', 'waiting_for_user', 'escalated', 'cancelled'],
        'pending_authorization' => ['approved', 'disapproved', 'waiting_for_user', 'escalated', 'cancelled'],
        'approved' => ['assigned', 'waiting_for_user', 'escalated', 'cancelled'],
        'assigned' => ['in_progress', 'waiting_for_user', 'escalated'],
        'in_progress' => ['work_completed', 'waiting_for_user', 'escalated'],
        'work_completed' => ['closed', 'in_progress', 'reopened'],
        'closed' => ['reopened'],
        'disapproved' => [],
        'waiting_for_user' => ['submitted', 'pending_authorization', 'approved', 'assigned', 'in_progress', 'reopened', 'escalated', 'cancelled'],
        'escalated' => ['pending_authorization', 'approved', 'disapproved', 'assigned', 'in_progress', 'reopened', 'cancelled'],
        'cancelled' => [],
        'reopened' => ['assigned', 'in_progress', 'waiting_for_user', 'escalated'],
    ];

    /** @return list<string> */
    private function nextOf(TicketStatus $status): array
    {
        return array_map(fn (TicketStatus $s) => $s->value, $status->allowedNext());
    }

    /** Move a ticket to any status WITHOUT the guards (to build a situation for a page or a scope). */
    private function forceStatus(MaintenanceRequest $ticket, TicketStatus $status): MaintenanceRequest
    {
        DB::table('requests')->where('id', $ticket->id)->update(['status' => $status->value]);

        return $ticket->fresh();
    }

    public function test_the_four_new_statuses_have_labels_and_fit_the_column(): void
    {
        $labels = [
            'waiting_for_user' => 'Waiting for User',
            'escalated' => 'Escalated',
            'cancelled' => 'Cancelled',
            'reopened' => 'Reopened',
        ];

        foreach ($labels as $value => $label) {
            $this->assertSame($label, TicketStatus::from($value)->label());
        }

        $values = array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases());

        $this->assertCount(12, $values);
        $this->assertSame($values, array_values(array_unique($values)));
        $this->assertSame($values, array_keys(TicketStatus::options()));

        foreach ($values as $value) {
            $this->assertLessThanOrEqual(30, strlen($value), "requests.status is string(30): {$value}");
        }
    }

    public function test_the_transition_table_is_exactly_this(): void
    {
        $this->assertCount(count(TicketStatus::cases()), self::MOVES, 'every status needs a row in MOVES');

        foreach (TicketStatus::cases() as $status) {
            $this->assertEqualsCanonicalizing(self::MOVES[$status->value], $this->nextOf($status), $status->value);
        }
    }

    public function test_no_status_moves_to_itself_or_lists_a_move_twice(): void
    {
        foreach (TicketStatus::cases() as $status) {
            $next = $this->nextOf($status);

            $this->assertNotContains($status->value, $next, $status->value);
            $this->assertSame($next, array_values(array_unique($next)), $status->value);
        }
    }

    public function test_the_old_moves_still_work_and_the_old_skips_are_still_refused(): void
    {
        $old = [
            ['submitted', 'pending_authorization'], ['submitted', 'approved'], ['submitted', 'disapproved'],
            ['pending_authorization', 'approved'], ['pending_authorization', 'disapproved'],
            ['approved', 'assigned'], ['assigned', 'in_progress'], ['in_progress', 'work_completed'],
            ['work_completed', 'closed'], ['work_completed', 'in_progress'],
        ];
        foreach ($old as [$from, $to]) {
            $this->assertTrue(TicketStatus::from($from)->canTransitionTo(TicketStatus::from($to)), "{$from} -> {$to}");
        }

        $skips = [
            ['submitted', 'assigned'], ['submitted', 'in_progress'], ['submitted', 'closed'],
            ['pending_authorization', 'assigned'], ['approved', 'in_progress'], ['approved', 'closed'],
            ['assigned', 'work_completed'], ['in_progress', 'closed'], ['in_progress', 'approved'],
            ['work_completed', 'approved'], ['disapproved', 'approved'], ['disapproved', 'reopened'],
            ['closed', 'in_progress'], ['closed', 'cancelled'],
        ];
        foreach ($skips as [$from, $to]) {
            $this->assertFalse(TicketStatus::from($from)->canTransitionTo(TicketStatus::from($to)), "{$from} -> {$to}");
        }
    }

    public function test_cancel_is_only_possible_before_the_ticket_is_assigned(): void
    {
        $canCancel = array_filter(TicketStatus::cases(), fn (TicketStatus $s) => $s->canTransitionTo(TicketStatus::Cancelled));

        $this->assertEqualsCanonicalizing(
            ['submitted', 'pending_authorization', 'approved', 'waiting_for_user', 'escalated'],
            array_values(array_map(fn (TicketStatus $s) => $s->value, $canCancel))
        );
    }

    public function test_reopen_is_only_for_completed_or_closed_work(): void
    {
        $fromReopenable = array_filter(
            TicketStatus::cases(),
            fn (TicketStatus $s) => $s->canTransitionTo(TicketStatus::Reopened) && ! in_array($s, [TicketStatus::WaitingForUser, TicketStatus::Escalated], true)
        );

        $this->assertEqualsCanonicalizing(
            ['work_completed', 'closed'],
            array_values(array_map(fn (TicketStatus $s) => $s->value, $fromReopenable))
        );
    }

    public function test_waiting_and_escalated_are_never_entered_from_finished_work(): void
    {
        foreach ([TicketStatus::WorkCompleted, TicketStatus::Closed, TicketStatus::Disapproved, TicketStatus::Cancelled] as $status) {
            $this->assertFalse($status->canTransitionTo(TicketStatus::WaitingForUser), $status->value);
            $this->assertFalse($status->canTransitionTo(TicketStatus::Escalated), $status->value);
        }
    }

    public function test_waiting_for_user_can_always_go_back_to_where_it_came_from(): void
    {
        foreach (TicketStatus::cases() as $status) {
            if ($status->canTransitionTo(TicketStatus::WaitingForUser)) {
                $this->assertTrue(TicketStatus::WaitingForUser->canTransitionTo($status), "back to {$status->value}");
            }
        }
    }

    public function test_every_status_can_be_reached_from_submitted(): void
    {
        $seen = [TicketStatus::Submitted];
        $queue = [TicketStatus::Submitted];

        while ($queue) {
            foreach (array_shift($queue)->allowedNext() as $next) {
                if (! in_array($next, $seen, true)) {
                    $seen[] = $next;
                    $queue[] = $next;
                }
            }
        }

        $this->assertCount(count(TicketStatus::cases()), $seen);
    }

    public function test_pending_completed_and_final_groups_do_not_overlap_wrongly(): void
    {
        $this->assertFalse(TicketStatus::Cancelled->isPending());
        $this->assertFalse(TicketStatus::Cancelled->isCompleted());
        $this->assertTrue(TicketStatus::Reopened->isPending());
        $this->assertTrue(TicketStatus::WaitingForUser->isPending());
        $this->assertTrue(TicketStatus::Escalated->isPending());

        foreach (TicketStatus::cases() as $status) {
            $this->assertFalse($status->isPending() && $status->isCompleted(), $status->value);
            $this->assertFalse($status->isPending() && $status->isTerminal(), $status->value);
        }
    }

    public function test_a_ticket_can_wait_for_the_requester_and_come_back(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        TicketWorkflow::transition($ticket, TicketStatus::WaitingForUser, $staff, 'Which room exactly?');
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);

        TicketWorkflow::transition($ticket, TicketStatus::Submitted, $staff, 'Answered.');
        TicketWorkflow::transition($ticket, TicketStatus::PendingAuthorization, $staff);

        $this->assertSame(
            ['submitted', 'waiting_for_user', 'submitted', 'pending_authorization'],
            $ticket->statusLogs()->pluck('to_status')->all()
        );
    }

    public function test_a_waiting_or_escalated_ticket_cannot_be_approved_or_sent_for_authorization_yet(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $president = $this->user('president@lcctanauan.edu.ph');

        foreach ([TicketStatus::WaitingForUser, TicketStatus::Escalated] as $status) {
            $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());
            TicketWorkflow::transition($ticket, $status, $staff);

            foreach ([
                fn () => TicketWorkflow::authorize($ticket, $president, 'OK'),
                fn () => TicketWorkflow::disapprove($ticket, $president, 'No'),
                fn () => TicketWorkflow::sendForAuthorization($ticket, $staff),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$status->value}: the action should have been refused.");
                } catch (DomainException) {
                    // expected
                }
            }

            $this->assertSame($status, $ticket->fresh()->status);
            $this->assertNotContains(TicketActions::APPROVE, TicketActions::for($ticket->fresh(), $president));
            $this->assertNotContains(TicketActions::SEND_FOR_AUTHORIZATION, TicketActions::for($ticket->fresh(), $staff));
        }
    }

    public function test_a_cancelled_ticket_is_final(): void
    {
        $this->seedBase();
        $requester = $this->user('requester@lcctanauan.edu.ph');
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($requester, $this->data());

        TicketWorkflow::transition($ticket, TicketStatus::Cancelled, $requester, 'Not needed anymore.');
        $ticket->refresh();

        $this->assertSame(TicketStatus::Cancelled, $ticket->status);
        $this->assertSame([], TicketActions::for($ticket, $staff));

        foreach ([
            fn () => TicketWorkflow::transition($ticket, TicketStatus::Submitted, $staff),
            fn () => TicketWorkflow::transition($ticket, TicketStatus::Reopened, $staff),
            fn () => TicketWorkflow::addNote($ticket, $staff, 'hello'),
            fn () => TicketWorkflow::flagEmergency($ticket, $staff, 'Water'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A cancelled ticket accepted a change.');
            } catch (DomainException) {
                // expected
            }
        }
    }

    public function test_the_model_guard_also_covers_the_new_statuses(): void
    {
        $this->seedBase();
        $ticket = $this->forceStatus($this->ticketAt(now()), TicketStatus::Assigned);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot move from "Assigned" to "Cancelled"');

        $ticket->update(['status' => TicketStatus::Cancelled]);
    }

    public function test_a_finished_ticket_can_be_reopened_and_worked_again(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data());

        TicketWorkflow::authorize($ticket, $this->user('president@lcctanauan.edu.ph'), 'OK');
        TicketWorkflow::startWork($ticket, $staff);
        TicketWorkflow::markWorkCompleted($ticket, $staff);
        TicketWorkflow::close($ticket, $staff);

        // Closed ticket: the normal ticket actions stay off, only the reopen move exists
        $this->assertSame([], TicketActions::for($ticket->fresh(), $staff));

        TicketWorkflow::transition($ticket, TicketStatus::Reopened, $staff, 'Leak came back.');
        TicketWorkflow::transition($ticket, TicketStatus::InProgress, $staff);
        TicketWorkflow::transition($ticket, TicketStatus::WorkCompleted, $staff);

        $this->assertSame(TicketStatus::WorkCompleted, $ticket->fresh()->status);
        $this->assertContains('reopened', $ticket->statusLogs()->pluck('to_status')->all());
    }

    public function test_pending_scope_counts_the_new_open_statuses_but_not_cancelled(): void
    {
        $this->seedBase();

        foreach (TicketStatus::cases() as $status) {
            $this->forceStatus($this->ticketAt(now()), $status);
        }

        $this->assertSame(count(TicketStatus::pendingCases()), MaintenanceRequest::pending()->count());
        $this->assertSame(0, MaintenanceRequest::pending()->where('status', 'cancelled')->count());
        $this->assertSame(0, MaintenanceRequest::query()->completed()->where('status', 'reopened')->count());
    }

    public function test_pages_render_for_every_status_with_a_badge_and_tracker(): void
    {
        $this->withoutVite();
        $this->seedBase();
        $admin = $this->user('maintenance@lcctanauan.edu.ph');

        $expect = [
            'waiting_for_user' => ['Waiting for User', 'stx-waiting-for-user', 'Waiting for the requester'],
            'escalated' => ['Escalated', 'stx-escalated', 'Waiting for the President'],
            'cancelled' => ['Cancelled', 'stx-cancelled', 'This ticket was cancelled'],
            'reopened' => ['Reopened', 'stx-reopened', 'Sent back for more work'],
        ];

        foreach ($expect as $value => [$label, $class, $trackerText]) {
            $ticket = $this->forceStatus(TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data()), TicketStatus::from($value));

            $this->actingAs($admin)->get(route('tickets.show', $ticket))
                ->assertOk()->assertSee($label)->assertSee($class)->assertSee($trackerText);

            // Phase 14: a finished status (Cancelled) is listed in Ticket History, the others in My Tickets
            $listRoute = TicketStatus::from($value)->isTerminal() ? 'tickets.history' : 'tickets.index';
            $this->actingAs($admin)->get(route($listRoute, ['status' => [$value]]))
                ->assertOk()->assertSee($ticket->ticket_no)->assertSee($class);
        }

        $this->actingAs($admin)->get(route('reports.index'))->assertOk();
        $this->actingAs($this->user('requester@lcctanauan.edu.ph'))->get(route('requester.dashboard'))->assertOk();
    }

    public function test_the_board_keeps_waiting_escalated_and_reopened_tickets_visible_without_buttons(): void
    {
        $this->withoutVite();
        $this->seedBase();
        $admin = $this->user('maintenance@lcctanauan.edu.ph');

        $shown = [];
        foreach ([TicketStatus::WaitingForUser, TicketStatus::Escalated, TicketStatus::Reopened] as $status) {
            $shown[] = $this->forceStatus(TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data()), $status)->ticket_no;
        }
        $cancelled = $this->forceStatus(TicketWorkflow::open($this->user('requester@lcctanauan.edu.ph'), $this->data()), TicketStatus::Cancelled)->ticket_no;

        $response = $this->actingAs($admin)->get(route('maintenance.board.cards'))->assertOk();

        foreach ($shown as $no) {
            $response->assertSee($no);
        }
        $response->assertDontSee($cancelled)->assertDontSee('ACCEPT TASK')->assertDontSee('MARK COMPLETED');
    }

    public function test_saved_tickets_in_the_old_statuses_are_untouched(): void
    {
        $this->seed();   // the sample data uses the original statuses only

        $old = ['submitted', 'pending_authorization', 'approved', 'assigned', 'in_progress', 'work_completed', 'closed', 'disapproved'];

        $this->assertSame(0, DB::table('requests')->whereNotIn('status', $old)->count());
        $this->assertSame(
            DB::table('requests')->count(),
            MaintenanceRequest::all()->filter(fn ($t) => $t->status instanceof TicketStatus)->count()
        );
    }

    // ---- sample data & pages -------------------------------------------------------------------

    public function test_the_seeder_builds_sample_tickets_for_every_status_and_month(): void
    {
        $this->seed();   // full DatabaseSeeder, includes TicketSeeder

        $tickets = MaintenanceRequest::all();
        $this->assertCount(40, $tickets);
        $this->assertCount(40, $tickets->pluck('ticket_no')->unique());

        // The sample data is the ORIGINAL 8 statuses on purpose (fixed seed: other tests count on it).
        // The four Phase 7 statuses get sample tickets when Phases 8-10 build their actions.
        $phase7 = ['waiting_for_user', 'escalated', 'cancelled', 'reopened'];
        $seen = $tickets->map(fn ($t) => $t->status->value)->unique()->all();
        foreach (TicketStatus::cases() as $status) {
            if (in_array($status->value, $phase7, true)) {
                $this->assertNotContains($status->value, $seen);

                continue;
            }
            $this->assertContains($status->value, $seen, "No sample ticket in status {$status->value}");
        }

        $this->assertGreaterThanOrEqual(10, $tickets->map(fn ($t) => $t->submitted_at->format('Y-m'))->unique()->count());

        foreach ($tickets as $t) {
            $this->assertGreaterThanOrEqual(1, $t->statusLogs()->count(), "{$t->ticket_no} has no history");
            if ($t->is_emergency) {
                $this->assertNotEmpty($t->emergency_reason);
            }
            if ($t->status === TicketStatus::Closed) {
                $this->assertNotNull($t->president_id);
                $this->assertNotNull($t->closed_at);
            }
            if ($t->status->isCompleted()) {
                $this->assertNotNull($t->completed_at);
            }
        }

        $this->assertGreaterThanOrEqual(3, $tickets->where('is_confidential', true)->count());
        $this->assertGreaterThanOrEqual(3, $tickets->where('is_emergency', true)->count());
    }

    public function test_the_board_and_dashboards_render_with_sample_data(): void
    {
        $this->withoutVite();
        $this->seed();

        $this->actingAs($this->user('maintenance@lcctanauan.edu.ph'))
            ->get(route('maintenance.board'))->assertOk()->assertSee('ACCEPT TASK');

        $this->actingAs($this->user('maintenance@lcctanauan.edu.ph'))
            ->get(route('maintenance.board.cards'))->assertOk();

        $this->actingAs($this->user('requester@lcctanauan.edu.ph'))->get(route('requester.dashboard'))->assertOk();
        $this->actingAs($this->user('finance@lcctanauan.edu.ph'))->get(route('finance.dashboard'))->assertOk();
        $this->actingAs($this->user('president@lcctanauan.edu.ph'))->get(route('president.dashboard'))->assertOk();
        $this->actingAs($this->user('requester@lcctanauan.edu.ph'))->get(route('repairs.create'))->assertOk();
    }
}
