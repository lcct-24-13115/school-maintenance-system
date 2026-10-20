<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_closed_and_disapproved_are_final(): void
    {
        $this->assertSame([], TicketStatus::Closed->allowedNext());
        $this->assertSame([], TicketStatus::Disapproved->allowedNext());
        $this->assertTrue(TicketStatus::Closed->isTerminal());
        $this->assertFalse(TicketStatus::InProgress->isTerminal());
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
        $this->assertEqualsCanonicalizing(
            ['submitted', 'pending_authorization', 'approved', 'assigned', 'in_progress'],
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

    // ---- sample data & pages -------------------------------------------------------------------

    public function test_the_seeder_builds_sample_tickets_for_every_status_and_month(): void
    {
        $this->seed();   // full DatabaseSeeder, includes TicketSeeder

        $tickets = MaintenanceRequest::all();
        $this->assertCount(40, $tickets);
        $this->assertCount(40, $tickets->pluck('ticket_no')->unique());

        $seen = $tickets->map(fn ($t) => $t->status->value)->unique()->all();
        foreach (TicketStatus::cases() as $status) {
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
