<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teacher files -> Vice Principal -> Finance -> President -> Head of Maintenance (schedules + assigns staff).
 * Written without being able to run PHP in the build environment: run `php artisan test --filter=ApprovalChainTest`.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug, ?string $dept = null): User
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $dept ? Department::where('code', $dept)->value('id') : null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function ticket(User $teacher): MaintenanceRequest
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(\Database\Seeders\CategorySeeder::class);

        return TicketWorkflow::open($teacher, [
            'department_id' => Department::maintenanceId(),
            'category_id' => Category::query()->value('id'),
            'location' => 'Room 101',
            'description' => 'Broken door',
            'reason' => 'Students cannot lock it',
        ]);
    }

    public function test_every_ticket_starts_a_three_step_chain_at_the_vice_principal(): void
    {
        $teacher = $this->user(Role::REQUESTER);
        $ticket = $this->ticket($teacher);

        $this->assertSame(TicketStatus::PendingAuthorization, $ticket->status);
        $this->assertSame(['vice_principal', 'finance', 'president'], $ticket->approvals->pluck('step')->all());
        $this->assertSame('vice_principal', $ticket->currentApproval()->role_slug);
    }

    public function test_steps_cannot_be_skipped_and_only_the_current_role_may_sign(): void
    {
        $ticket = $this->ticket($this->user(Role::REQUESTER));
        $president = $this->user(Role::PRESIDENT);

        $this->assertFalse($president->can('authorize', $ticket));

        $this->expectException(DomainException::class);
        TicketWorkflow::authorize($ticket, $president, 'skipping');
    }

    public function test_full_chain_ends_approved_and_finance_needs_an_amount(): void
    {
        $ticket = $this->ticket($this->user(Role::REQUESTER));
        $vp = $this->user(Role::VICE_PRINCIPAL);
        $finance = $this->user(Role::FINANCE);
        $president = $this->user(Role::PRESIDENT);

        $ticket = TicketWorkflow::authorize($ticket, $vp);
        $this->assertSame(TicketStatus::PendingAuthorization, $ticket->status);
        $this->assertNull($ticket->president_approved_at);

        try {
            TicketWorkflow::authorize($ticket, $finance);   // no amount
            $this->fail('Finance must record an amount.');
        } catch (DomainException) {
        }

        $ticket = TicketWorkflow::authorize($ticket, $finance, null, [], 1200);
        $this->assertSame(TicketStatus::PendingAuthorization, $ticket->status);

        $ticket = TicketWorkflow::authorize($ticket, $president);
        $this->assertSame(TicketStatus::Approved, $ticket->status);
        $this->assertTrue($ticket->isPresidentApproved());
        $this->assertEquals(1200, $ticket->approvals->firstWhere('step', 'finance')->amount);
    }

    public function test_disapproval_at_any_step_stops_the_chain(): void
    {
        $ticket = $this->ticket($this->user(Role::REQUESTER));
        $vp = $this->user(Role::VICE_PRINCIPAL);

        $ticket = TicketWorkflow::disapprove($ticket, $vp, 'Not needed');

        $this->assertSame(TicketStatus::Disapproved, $ticket->status);
        $this->assertNull($ticket->currentApproval());
    }

    public function test_maintenance_only_sees_a_ticket_after_the_president_approved(): void
    {
        $teacher = $this->user(Role::REQUESTER);
        $ticket = $this->ticket($teacher);
        $head = $this->user(Role::MAINTENANCE_ADMIN, Department::MAINTENANCE_CODE);

        $this->assertFalse($ticket->isVisibleTo($head));
        $this->assertSame(0, MaintenanceRequest::query()->visibleTo($head)->count());

        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::VICE_PRINCIPAL));
        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::FINANCE), null, [], 1000);
        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::PRESIDENT));

        $this->assertTrue($ticket->isVisibleTo($head));
    }

    public function test_head_must_set_a_target_date_when_assigning(): void
    {
        $ticket = $this->ticket($this->user(Role::REQUESTER));
        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::VICE_PRINCIPAL));
        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::FINANCE), null, [], 1000);
        $ticket = TicketWorkflow::authorize($ticket, $this->user(Role::PRESIDENT));

        $head = $this->user(Role::MAINTENANCE_ADMIN, Department::MAINTENANCE_CODE);
        $staff = $this->user(Role::STAFF, Department::MAINTENANCE_CODE);

        try {
            TicketWorkflow::assign($ticket, $staff, $head);
            $this->fail('A target date is required.');
        } catch (DomainException) {
        }

        $ticket = TicketWorkflow::assign($ticket, $staff, $head, null, now()->addDays(5)->toDateString(), now()->addDay()->toDateString());
        $this->assertSame(TicketStatus::Assigned, $ticket->status);
        $this->assertSame(now()->addDays(5)->toDateString(), $ticket->due_at->toDateString());
    }
}
