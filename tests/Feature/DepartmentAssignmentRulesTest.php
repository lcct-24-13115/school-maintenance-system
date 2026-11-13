<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 4C: who may CLAIM / ASSIGN / TRANSFER / NOTE a ticket, by role x department.
 *
 *  - Department Staff claim / transfer only inside their own department
 *  - Department Head assigns anyone in the department
 *  - cross-department transfer (assignee outside the ticket's department) is Head / Admin only, with a reason
 *  - a hidden ticket is a 404 (never 403), even for claim / assign / transfer / notes
 *  - Finance staff (staff in Finance) cannot see / touch a Registrar ticket
 */
class DepartmentAssignmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->requester = $this->make('requester', 'FIN');
        $this->systemAdmin = $this->make('system_admin', null);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function dept(string $code): int
    {
        return (int) Department::where('code', $code)->value('id');
    }

    private function make(string $slug, ?string $deptCode = null): User
    {
        return User::create([
            'name' => 'Test '.$slug.' '.$deptCode,
            'email' => $slug.'-'.($deptCode ?? 'none').'-'.uniqid().'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $deptCode === null ? null : $this->dept($deptCode),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /** An Approved ticket that the given office RECEIVES (assigned_department_id). Filed by the FIN requester. */
    private function approved(string $assignedDeptCode): MaintenanceRequest
    {
        $ticket = MaintenanceRequest::create([
            'requester_id' => $this->requester->id,
            'department_id' => $this->dept('FIN'),
            'assigned_department_id' => $this->dept($assignedDeptCode),
            'category_id' => Category::query()->orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Room '.$assignedDeptCode,
            'description' => 'Ticket for '.$assignedDeptCode,
        ]);

        TicketWorkflow::authorize($ticket, $this->systemAdmin);

        return $ticket->refresh();
    }

    // ---- capability --------------------------------------------------------------------------------

    public function test_cross_department_transfer_is_head_admin_and_system_admin_only(): void
    {
        $this->assertEqualsCanonicalizing(['head', 'maintenance_admin', 'system_admin'], Role::crossDepartmentSlugs());

        foreach (['head', 'maintenance_admin', 'system_admin'] as $slug) {
            $this->assertTrue($this->make($slug, 'FIN')->canTransferCrossDepartment(), $slug);
        }

        foreach (['staff', 'requester', 'finance', 'custodian'] as $slug) {
            $this->assertFalse($this->make($slug, 'FIN')->canTransferCrossDepartment(), $slug);
        }
    }

    // ---- claim -------------------------------------------------------------------------------------

    public function test_matrix_claim_role_x_department(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');
        $headFin = $this->make('head', 'FIN');

        // Staff in Finance claims a Finance ticket.
        $finTicket = $this->approved('FIN');
        $this->assertTrue(Gate::forUser($staffFin)->allows('claim', $finTicket));
        $this->assertContains(TicketActions::CLAIM, TicketActions::for($finTicket, $staffFin));
        $this->actingAs($staffFin)->post(route('tickets.claim', $finTicket))->assertSessionHas('status');
        $this->assertEquals($staffFin->id, $finTicket->fresh()->assigned_to);

        // Staff in Finance cannot claim a Registrar ticket: hidden = 404, and the workflow refuses a direct call.
        $regTicket = $this->approved('REG');
        $this->assertFalse(Gate::forUser($staffFin)->allows('claim', $regTicket));
        $this->actingAs($staffFin)->post(route('tickets.claim', $regTicket))->assertNotFound();
        $this->assertNull($regTicket->fresh()->assigned_to);

        $this->expectException(DomainException::class);
        TicketWorkflow::claim($regTicket->fresh(), $staffFin);
    }

    public function test_claim_is_staff_only_and_stays_in_the_own_department(): void
    {
        $staffReg = $this->make('staff', 'REG');
        $headFin = $this->make('head', 'FIN');
        $regTicket = $this->approved('REG');

        // Registrar staff claims the Registrar ticket.
        $this->actingAs($staffReg)->post(route('tickets.claim', $regTicket))->assertSessionHas('status');
        $this->assertEquals($staffReg->id, $regTicket->fresh()->assigned_to);

        // Head and requester never claim (they assign / file instead).
        $finTicket = $this->approved('FIN');
        $this->assertFalse(Gate::forUser($headFin)->allows('claim', $finTicket));
        $this->actingAs($headFin)->post(route('tickets.claim', $finTicket))->assertForbidden();
        $this->actingAs($this->requester)->post(route('tickets.claim', $finTicket))->assertForbidden();
        $this->actingAs($this->systemAdmin)->post(route('tickets.claim', $finTicket))->assertForbidden();
        $this->assertNull($finTicket->fresh()->assigned_to);
    }

    // ---- assign ------------------------------------------------------------------------------------

    public function test_matrix_assign_role_x_department(): void
    {
        $headFin = $this->make('head', 'FIN');
        $staffFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');

        // Head in Finance assigns a Finance ticket to Finance staff.
        $finTicket = $this->approved('FIN');
        $this->assertTrue(Gate::forUser($headFin)->allows('assign', $finTicket));
        $this->actingAs($headFin)->post(route('tickets.assign', $finTicket), ['assignee_id' => $staffFin->id])
            ->assertSessionHas('status');
        $this->assertEquals($staffFin->id, $finTicket->fresh()->assigned_to);

        // Head in Finance cannot assign a Registrar ticket: hidden = 404.
        $regTicket = $this->approved('REG');
        $this->assertFalse(Gate::forUser($headFin)->allows('assign', $regTicket));
        $this->actingAs($headFin)->post(route('tickets.assign', $regTicket), ['assignee_id' => $staffReg->id])
            ->assertNotFound();
        $this->assertNull($regTicket->fresh()->assigned_to);
    }

    public function test_cross_department_assign_needs_a_reason_and_head_admin_only(): void
    {
        $headFin = $this->make('head', 'FIN');
        $staffFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');

        // Head in Finance assigns a Finance ticket to Registrar staff: cross-department, reason required.
        $finTicket = $this->approved('FIN');
        $this->assertTrue(TicketWorkflow::isCrossDepartmentAssignment($finTicket, $staffReg));
        $this->assertFalse(TicketWorkflow::isCrossDepartmentAssignment($finTicket, $staffFin));

        $this->actingAs($headFin)->post(route('tickets.assign', $finTicket), ['assignee_id' => $staffReg->id])
            ->assertSessionHasErrors('assign_note', null, 'assign');
        $this->assertNull($finTicket->fresh()->assigned_to);

        $this->actingAs($headFin)->post(route('tickets.assign', $finTicket), [
            'assignee_id' => $staffReg->id,
            'assign_note' => 'Registrar staff have the spare part.',
        ])->assertSessionHas('status');
        $this->assertEquals($staffReg->id, $finTicket->fresh()->assigned_to);

        // The cross-assigned Registrar staff member sees the Finance ticket as assignee.
        $this->assertTrue($finTicket->fresh()->isVisibleTo($staffReg));
        $this->actingAs($staffReg)->get(route('tickets.show', $finTicket))->assertOk();

        // Staff can never do a cross-department assignment, even with a reason.
        $otherFinTicket = $this->approved('FIN');
        $this->actingAs($staffFin)->post(route('tickets.assign', $otherFinTicket), [
            'assignee_id' => $staffReg->id,
            'assign_note' => 'Trying cross-department.',
        ])->assertForbidden();
        $this->assertNull($otherFinTicket->fresh()->assigned_to);
    }

    public function test_system_admin_assigns_across_departments_with_a_reason(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');

        // Same-department: no reason needed.
        $regTicket = $this->approved('REG');
        $this->actingAs($this->systemAdmin)->post(route('tickets.assign', $regTicket), ['assignee_id' => $staffReg->id])
            ->assertSessionHas('status');
        $this->assertEquals($staffReg->id, $regTicket->fresh()->assigned_to);

        // Cross-department: reason required.
        $otherRegTicket = $this->approved('REG');
        $this->actingAs($this->systemAdmin)->post(route('tickets.assign', $otherRegTicket), ['assignee_id' => $staffFin->id])
            ->assertSessionHasErrors('assign_note', null, 'assign');
        $this->actingAs($this->systemAdmin)->post(route('tickets.assign', $otherRegTicket), [
            'assignee_id' => $staffFin->id,
            'assign_note' => 'Finance staff will handle this.',
        ])->assertSessionHas('status');
        $this->assertEquals($staffFin->id, $otherRegTicket->fresh()->assigned_to);
    }

    public function test_requester_and_finance_cannot_assign(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $finance = $this->make('finance', 'FIN');
        $finTicket = $this->approved('FIN');

        $this->assertFalse(Gate::forUser($this->requester)->allows('assign', $finTicket));
        $this->actingAs($this->requester)->post(route('tickets.assign', $finTicket), ['assignee_id' => $staffFin->id])
            ->assertForbidden();

        // Finance sees the ticket (global read-only) but cannot assign it.
        $this->assertTrue($finTicket->isVisibleTo($finance));
        $this->assertFalse(Gate::forUser($finance)->allows('assign', $finTicket));
        $this->actingAs($finance)->post(route('tickets.assign', $finTicket), ['assignee_id' => $staffFin->id])
            ->assertForbidden();

        $this->assertNull($finTicket->fresh()->assigned_to);
    }

    // ---- transfer ----------------------------------------------------------------------------------

    public function test_matrix_transfer_role_x_department(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $colleagueFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');
        $headFin = $this->make('head', 'FIN');

        $finTicket = $this->approved('FIN');
        TicketWorkflow::claim($finTicket, $staffFin);

        // Staff transfers to a colleague in the same department, with a reason.
        $this->actingAs($staffFin)->post(route('tickets.transfer', $finTicket), [
            'transfer_to' => $colleagueFin->id,
            'transfer_reason' => 'I am on leave tomorrow.',
        ])->assertSessionHas('status');
        $this->assertEquals($colleagueFin->id, $finTicket->fresh()->assigned_to);

        // Staff cannot transfer to another department, even with a reason.
        $otherFinTicket = $this->approved('FIN');
        TicketWorkflow::claim($otherFinTicket, $staffFin);
        $this->actingAs($staffFin)->post(route('tickets.transfer', $otherFinTicket), [
            'transfer_to' => $staffReg->id,
            'transfer_reason' => 'Registrar should do this.',
        ])->assertSessionHas('error');
        $this->assertEquals($staffFin->id, $otherFinTicket->fresh()->assigned_to);

        // Head never uses the transfer endpoint (they reassign instead).
        $this->actingAs($headFin)->post(route('tickets.transfer', $otherFinTicket), [
            'transfer_to' => $colleagueFin->id,
            'transfer_reason' => 'Head reassigns instead.',
        ])->assertForbidden();
    }

    public function test_finance_staff_cannot_transfer_or_touch_a_registrar_ticket(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');
        $regTicket = $this->approved('REG');
        TicketWorkflow::claim($regTicket, $staffReg);

        // Hidden = 404 for every action, not 403.
        $this->actingAs($staffFin)->get(route('tickets.show', $regTicket))->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.claim', $regTicket))->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.assign', $regTicket), ['assignee_id' => $staffFin->id])->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.transfer', $regTicket), [
            'transfer_to' => $staffFin->id,
            'transfer_reason' => 'Taking this away.',
        ])->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.notes.store', $regTicket), ['note' => 'Hello'])->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.replies.store', $regTicket), ['body' => 'Hello'])->assertNotFound();

        $this->assertEquals($staffReg->id, $regTicket->fresh()->assigned_to);
    }

    // ---- notes -------------------------------------------------------------------------------------

    public function test_matrix_notes_role_x_department(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $headFin = $this->make('head', 'FIN');
        $staffReg = $this->make('staff', 'REG');
        $finance = $this->make('finance', 'FIN');

        $finTicket = $this->approved('FIN');
        $regTicket = $this->approved('REG');

        // Staff / Head in Finance can note and reply on the Finance ticket.
        foreach ([$staffFin, $headFin] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('addNote', $finTicket));
            $this->assertTrue(Gate::forUser($user)->allows('reply', $finTicket));
            $this->actingAs($user)->post(route('tickets.notes.store', $finTicket), ['note' => 'Noted by '.$user->name])
                ->assertSessionHas('status');
            $this->actingAs($user)->post(route('tickets.replies.store', $finTicket), ['body' => 'Reply from '.$user->name])
                ->assertSessionHas('status');
        }

        // The requester can note / reply on their own tickets in any department.
        $this->actingAs($this->requester)->post(route('tickets.notes.store', $regTicket), ['note' => 'My ticket'])
            ->assertSessionHas('status');
        $this->actingAs($this->requester)->post(route('tickets.replies.store', $regTicket), ['body' => 'My reply'])
            ->assertSessionHas('status');

        // System Administrator can note / reply on every ticket.
        $this->actingAs($this->systemAdmin)->post(route('tickets.notes.store', $regTicket), ['note' => 'Admin note'])
            ->assertSessionHas('status');
        $this->actingAs($this->systemAdmin)->post(route('tickets.replies.store', $regTicket), ['body' => 'Admin reply'])
            ->assertSessionHas('status');

        // Finance staff cannot note / reply on the Registrar ticket (404), and the finance role is view-only (403).
        $this->assertFalse(Gate::forUser($staffFin)->allows('addNote', $regTicket));
        $this->assertFalse(Gate::forUser($staffFin)->allows('reply', $regTicket));
        $this->actingAs($staffFin)->post(route('tickets.notes.store', $regTicket), ['note' => 'Nope'])->assertNotFound();
        $this->actingAs($staffFin)->post(route('tickets.replies.store', $regTicket), ['body' => 'Nope'])->assertNotFound();

        $this->assertFalse(Gate::forUser($finance)->allows('addNote', $finTicket));
        $this->actingAs($finance)->post(route('tickets.notes.store', $finTicket), ['note' => 'View only'])->assertForbidden();

        // Registrar staff can note the Registrar ticket.
        $this->actingAs($staffReg)->post(route('tickets.notes.store', $regTicket), ['note' => 'Registrar note'])
            ->assertSessionHas('status');
    }

    // ---- dropdown ----------------------------------------------------------------------------------

    public function test_the_transfer_dropdown_is_scoped_to_the_own_department(): void
    {
        $staffFin = $this->make('staff', 'FIN');
        $colleagueFin = $this->make('staff', 'FIN');
        $staffReg = $this->make('staff', 'REG');
        $headFin = $this->make('head', 'FIN');

        $finTicket = $this->approved('FIN');
        TicketWorkflow::claim($finTicket, $staffFin);

        $forStaff = TicketActions::assignableStaffFor($finTicket->fresh(), $staffFin)->pluck('id')->all();
        $this->assertContains($colleagueFin->id, $forStaff);
        $this->assertNotContains($staffReg->id, $forStaff);

        // Head / System Administrator see everyone (cross-department can be picked, reason still required).
        $forHead = TicketActions::assignableStaffFor($finTicket->fresh(), $headFin)->pluck('id')->all();
        $this->assertContains($colleagueFin->id, $forHead);
        $this->assertContains($staffReg->id, $forHead);

        $forAdmin = TicketActions::assignableStaffFor($finTicket->fresh(), $this->systemAdmin)->pluck('id')->all();
        $this->assertContains($staffReg->id, $forAdmin);
    }
}
