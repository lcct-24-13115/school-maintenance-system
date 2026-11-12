<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff rules on a ticket: a Maintenance Staff member may CLAIM an unassigned ticket and TRANSFER their OWN
 * ticket (a reason is required). They may not assign to other people or touch other people's tickets.
 * The Head and the Maintenance Admin keep Assign / Reassign.
 */
class StaffTicketRulesTest extends TestCase
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
        config(['school.workflow.authorizers' => ['president']]);
        config(['school.workflow.closers' => ['maintenance_admin', 'president']]);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function member(string $key, string $roleSlug, bool $active = true): User
    {
        return User::updateOrCreate(['email' => $key.'-rules@lcctanauan.edu.ph'], [
            'name' => 'Test '.$key,
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'email_verified_at' => now(),
            'is_active' => $active,
        ]);
    }

    private function seeded(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function approved(): MaintenanceRequest
    {
        $requester = $this->seeded('requester');

        $ticket = TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
        TicketWorkflow::authorize($ticket, $this->seeded('president'));

        return $ticket;
    }

    // ---- claim ----------------------------------------------------------------------------------

    public function test_staff_sees_claim_on_an_unassigned_approved_ticket_and_not_assign(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $ticket = $this->approved();

        $actions = TicketActions::for($ticket->fresh(), $staff);
        $this->assertContains(TicketActions::CLAIM, $actions);
        $this->assertNotContains(TicketActions::ASSIGN, $actions);
        $this->assertNotContains(TicketActions::TRANSFER, $actions);

        $this->actingAs($staff)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Claim this ticket');
    }

    public function test_staff_can_claim_an_unassigned_ticket_without_starting_work(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $ticket = $this->approved();

        $this->actingAs($staff)->post(route('tickets.claim', $ticket))->assertSessionHas('status');

        $ticket->refresh();
        $this->assertEquals($staff->id, $ticket->assigned_to);
        $this->assertSame(TicketStatus::Assigned, $ticket->status);   // claimed, not started
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id, 'action' => RequestStatusLog::ACTION_ASSIGNMENT, 'to_status' => 'assigned',
        ]);
    }

    public function test_staff_cannot_claim_a_ticket_a_colleague_already_has(): void
    {
        $first = $this->member('staff1', Role::STAFF);
        $second = $this->member('staff2', Role::STAFF);
        $ticket = $this->approved();

        $this->actingAs($first)->post(route('tickets.claim', $ticket))->assertSessionHas('status');
        $this->actingAs($second)->post(route('tickets.claim', $ticket))->assertSessionHas('error');

        $this->assertEquals($first->id, $ticket->fresh()->assigned_to);
    }

    public function test_staff_cannot_claim_a_ticket_that_is_not_approved_yet(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $requester = $this->seeded('requester');
        $ticket = TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Library',
            'description' => 'Broken door',
        ]);

        $this->assertNotContains(TicketActions::CLAIM, TicketActions::for($ticket->fresh(), $staff));

        $this->actingAs($staff)->post(route('tickets.claim', $ticket))->assertSessionHas('error');
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_only_the_staff_role_can_claim(): void
    {
        $ticket = $this->approved();

        foreach ([$this->seeded('requester'), $this->member('head1', Role::HEAD), $this->seeded('maintenance')] as $user) {
            $this->actingAs($user)->post(route('tickets.claim', $ticket))->assertForbidden();
        }

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_head_and_admin_keep_the_assign_form_and_never_see_claim_or_transfer(): void
    {
        $ticket = $this->approved();

        foreach ([$this->member('head1', Role::HEAD), $this->seeded('maintenance')] as $user) {
            $actions = TicketActions::for($ticket->fresh(), $user);
            $this->assertContains(TicketActions::ASSIGN, $actions);
            $this->assertNotContains(TicketActions::CLAIM, $actions);
            $this->assertNotContains(TicketActions::TRANSFER, $actions);
        }
    }

    // ---- transfer -------------------------------------------------------------------------------

    public function test_staff_sees_transfer_instead_of_reassign_on_their_own_ticket(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);

        $actions = TicketActions::for($ticket->fresh(), $staff);
        $this->assertContains(TicketActions::TRANSFER, $actions);
        $this->assertNotContains(TicketActions::REASSIGN, $actions);
        $this->assertNotContains(TicketActions::CLAIM, $actions);

        $this->actingAs($staff)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Transfer this ticket');
    }

    public function test_staff_can_transfer_their_own_ticket_with_a_reason(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $colleague = $this->member('staff2', Role::STAFF);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);

        $this->actingAs($staff)->post(route('tickets.transfer', $ticket), [
            'transfer_to' => $colleague->id,
            'transfer_reason' => 'I am on leave tomorrow.',
        ])->assertSessionHas('status');

        $ticket->refresh();
        $this->assertEquals($colleague->id, $ticket->assigned_to);

        $log = RequestStatusLog::where('request_id', $ticket->id)->where('action', RequestStatusLog::ACTION_REASSIGNMENT)->firstOrFail();
        $this->assertSame('I am on leave tomorrow.', $log->meta['reason']);
        $this->assertEquals($staff->id, $log->meta['from_user_id']);
        $this->assertEquals($colleague->id, $log->meta['to_user_id']);
    }

    public function test_a_transfer_needs_a_reason(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $colleague = $this->member('staff2', Role::STAFF);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);
        $url = route('tickets.transfer', $ticket);

        $this->actingAs($staff)->post($url, ['transfer_to' => $colleague->id, 'transfer_reason' => ''])
            ->assertSessionHasErrors('transfer_reason', null, 'transfer');
        $this->actingAs($staff)->post($url, ['transfer_to' => $colleague->id, 'transfer_reason' => '  ok '])
            ->assertSessionHasErrors('transfer_reason', null, 'transfer');
        $this->actingAs($staff)->post($url, ['transfer_reason' => 'Need a break from this one.'])
            ->assertSessionHasErrors('transfer_to', null, 'transfer');

        $this->assertEquals($staff->id, $ticket->fresh()->assigned_to);
    }

    public function test_staff_cannot_transfer_a_ticket_that_is_not_theirs(): void
    {
        $owner = $this->member('staff1', Role::STAFF);
        $intruder = $this->member('staff2', Role::STAFF);
        $third = $this->member('staff3', Role::STAFF);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $owner);

        $this->assertNotContains(TicketActions::TRANSFER, TicketActions::for($ticket->fresh(), $intruder));

        $this->actingAs($intruder)->post(route('tickets.transfer', $ticket), [
            'transfer_to' => $third->id,
            'transfer_reason' => 'Taking this away from you.',
        ])->assertSessionHas('error');

        $this->assertEquals($owner->id, $ticket->fresh()->assigned_to);
    }

    public function test_staff_cannot_transfer_an_unassigned_ticket(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $colleague = $this->member('staff2', Role::STAFF);
        $ticket = $this->approved();

        $this->actingAs($staff)->post(route('tickets.transfer', $ticket), [
            'transfer_to' => $colleague->id,
            'transfer_reason' => 'Giving away a ticket I do not own.',
        ])->assertSessionHas('error');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_staff_cannot_transfer_to_themselves_to_an_inactive_account_or_to_a_requester(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $inactive = $this->member('staff2', Role::STAFF, false);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);
        $url = route('tickets.transfer', $ticket);

        foreach ([$staff, $inactive, $this->seeded('requester')] as $target) {
            $this->actingAs($staff)->post($url, [
                'transfer_to' => $target->id,
                'transfer_reason' => 'Trying an invalid target.',
            ])->assertSessionHas('error');
        }

        $this->assertEquals($staff->id, $ticket->fresh()->assigned_to);
    }

    public function test_only_the_staff_role_can_use_transfer(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $colleague = $this->member('staff2', Role::STAFF);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);

        foreach ([$this->seeded('requester'), $this->member('head1', Role::HEAD), $this->seeded('maintenance')] as $user) {
            $this->actingAs($user)->post(route('tickets.transfer', $ticket), [
                'transfer_to' => $colleague->id,
                'transfer_reason' => 'Not a staff member at all.',
            ])->assertForbidden();
        }

        $this->assertEquals($staff->id, $ticket->fresh()->assigned_to);
    }

    public function test_the_head_can_still_reassign_a_staff_members_ticket(): void
    {
        $staff = $this->member('staff1', Role::STAFF);
        $colleague = $this->member('staff2', Role::STAFF);
        $head = $this->member('head1', Role::HEAD);
        $ticket = $this->approved();
        TicketWorkflow::claim($ticket, $staff);

        $this->assertContains(TicketActions::REASSIGN, TicketActions::for($ticket->fresh(), $head));

        $this->actingAs($head)->post(route('tickets.assign', $ticket), [
            'assignee_id' => $colleague->id,
            'assign_note' => 'Rebalancing the queue.',
        ])->assertSessionHas('status');

        $this->assertEquals($colleague->id, $ticket->fresh()->assigned_to);
    }
}
