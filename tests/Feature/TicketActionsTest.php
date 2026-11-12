<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\User;
use App\Services\TicketActions;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A: school.workflow config, the Action panel, the approvals inbox, assignment, emergency flags,
 * notes and the row lock in TicketWorkflow::apply(). (Work execution and closure are Phase 4B.)
 */
class TicketActionsTest extends TestCase
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
        config(['school.workflow.authorizers' => ['president']]);   // these tests were written for the President as authorizer
        config(['school.workflow.closers' => ['maintenance_admin', 'president']]);

        // Accounts the older tests expect but UserSeeder no longer creates
        $maint = \App\Models\Role::where('slug', 'maintenance_admin')->value('id');
        $req = \App\Models\Role::where('slug', 'requester')->value('id');
        $dept = \App\Models\Department::query()->orderBy('id')->value('id');
        foreach ([['maintenance2', 'Test Maintenance Staff 2', $maint, \App\Models\Department::maintenanceId()], ['maintenance3', 'Test Maintenance Staff 3', $maint, \App\Models\Department::maintenanceId()], ['requester2', 'Test Requester 2', $req, $dept]] as [$key, $name, $role, $d]) {
            \App\Models\User::updateOrCreate(['email' => $key.'@lcctanauan.edu.ph'], [
                'name' => $name,
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role_id' => $role,
                'department_id' => $d,
                'email_verified_at' => now(),
            ]);
        }
        \App\Models\User::where('email', 'maintenance@lcctanauan.edu.ph')->update(['name' => 'Test Maintenance Admin']);
    }

    // ---- helpers --------------------------------------------------------------------------------

    /** user('requester') => requester@lcctanauan.edu.ph */
    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function ticket(array $overrides = []): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return TicketWorkflow::open($requester, $overrides + [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
    }

    /** A ticket created straight through the model, for exact submission times. */
    private function ticketAt($submittedAt): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return MaintenanceRequest::create([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Queue test',
            'submitted_at' => $submittedAt,
        ]);
    }

    private function approved(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));

        return $ticket;
    }

    private function assigned(string $to = 'maintenance'): MaintenanceRequest
    {
        $ticket = $this->approved();
        TicketWorkflow::assign($ticket, $this->user($to), $this->user('maintenance'));

        return $ticket;
    }

    private function disapproved(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::disapprove($ticket, $this->user('president'), 'Not needed.');

        return $ticket;
    }

    private function pdf(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    private function logCount(MaintenanceRequest $ticket): int
    {
        return $ticket->statusLogs()->count();
    }

    // ---- config: school.workflow ----------------------------------------------------------------

    public function test_the_workflow_config_has_the_documented_defaults(): void
    {
        $this->assertSame(['president'], config('school.workflow.authorizers'));
        $this->assertSame(['maintenance_admin', 'president'], config('school.workflow.closers'));
        $this->assertSame(['authorization', 'signed_evidence'], config('school.workflow.closure_requires'));
        $this->assertTrue(config('school.workflow.authorize_from_submitted'));
    }

    public function test_who_may_authorize_comes_from_config_not_from_the_president_role(): void
    {
        config(['school.workflow.authorizers' => ['finance']]);
        $ticket = $this->ticket();

        // the President is no longer an authorizer ...
        try {
            TicketWorkflow::authorize($ticket, $this->user('president'));
            $this->fail('The President should not be able to authorize when only finance is configured.');
        } catch (DomainException $e) {
            $this->assertSame('Only the Finance can authorize a ticket.', $e->getMessage());
        }

        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket))->assertForbidden();
        $this->actingAs($this->user('president'))->get(route('approvals.index'))->assertForbidden();

        // ... and Finance is
        $this->actingAs($this->user('finance'))->post(route('tickets.approve', $ticket))->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::Approved, $ticket->status);
        $this->assertEquals($this->user('finance')->id, $ticket->president_id);
    }

    public function test_an_inactive_authorizer_cannot_authorize(): void
    {
        $president = $this->user('president');
        $president->update(['is_active' => false]);
        $ticket = $this->ticket();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only the School President can authorize a ticket.');

        TicketWorkflow::authorize($ticket, $president);
    }

    public function test_authorize_from_submitted_can_be_turned_off(): void
    {
        config(['school.workflow.authorize_from_submitted' => false]);
        $president = $this->user('president');
        $ticket = $this->ticket();

        // the panel and the inbox do not offer it
        $this->actingAs($president)->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(route('tickets.approve', $ticket))
            ->assertDontSee(route('tickets.disapprove', $ticket));
        $this->assertSame([], $this->listedIds($president));

        // and the server refuses it
        $this->actingAs($president)->post(route('tickets.approve', $ticket))->assertSessionHas('error');
        $this->actingAs($president)->post(route('tickets.disapprove', $ticket), ['reason' => 'No'])->assertSessionHas('error');
        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);

        // after Maintenance sends it for authorization it works
        TicketWorkflow::sendForAuthorization($ticket, $this->user('maintenance'));
        $this->assertSame([$ticket->id], $this->listedIds($president));
        $this->actingAs($president)->post(route('tickets.approve', $ticket))->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Approved, $ticket->fresh()->status);
    }

    private function listedIds(User $user): array
    {
        return $this->actingAs($user)->get(route('approvals.index'))->assertOk()
            ->viewData('tickets')->getCollection()->pluck('id')->all();
    }

    // ---- send for authorization -----------------------------------------------------------------

    public function test_admin_sends_a_submitted_ticket_for_authorization(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.send-for-authorization', $ticket))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(TicketStatus::PendingAuthorization, $ticket->fresh()->status);
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id, 'action' => 'status_change',
            'from_status' => 'submitted', 'to_status' => 'pending_authorization',
        ]);
    }

    public function test_only_the_right_role_can_send_for_authorization(): void
    {
        $ticket = $this->ticket();
        $url = route('tickets.send-for-authorization', $ticket);

        $this->actingAs($this->user('requester'))->post($url)->assertForbidden();
        $this->actingAs($this->user('president'))->post($url)->assertForbidden();
        $this->actingAs($this->user('finance'))->post($url)->assertForbidden();
        $this->actingAs($this->user('depthead'))->post($url)->assertForbidden();
        $this->actingAs($this->user('requester2'))->post($url)->assertNotFound();   // cannot even see the ticket

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    public function test_sending_for_authorization_from_the_wrong_status_gives_a_clear_error(): void
    {
        $ticket = $this->approved();
        $before = $this->logCount($ticket);

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.send-for-authorization', $ticket))
            ->assertSessionHas('error');

        $this->assertSame(TicketStatus::Approved, $ticket->fresh()->status);
        $this->assertSame($before, $this->logCount($ticket));
    }

    // ---- approve / disapprove -------------------------------------------------------------------

    public function test_the_authorizer_approves_and_authorization_is_recorded(): void
    {
        $president = $this->user('president');
        $ticket = $this->ticket();

        $this->actingAs($president)->post(route('tickets.approve', $ticket), ['remarks' => 'Go ahead.'])
            ->assertRedirect()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Approved, $ticket->status);
        $this->assertEquals($president->id, $ticket->president_id);
        $this->assertNotNull($ticket->president_approved_at);
        $this->assertSame('Go ahead.', $ticket->president_remarks);
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id, 'action' => 'authorization', 'to_status' => 'approved', 'note' => 'Go ahead.',
        ]);
    }

    public function test_approving_from_pending_authorization_works_too(): void
    {
        $ticket = $this->ticket();
        TicketWorkflow::sendForAuthorization($ticket, $this->user('maintenance'));

        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket))->assertSessionHas('status');

        $this->assertSame(TicketStatus::Approved, $ticket->fresh()->status);
    }

    public function test_approving_can_attach_a_signed_file_as_signed_evidence(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket), [
            'files' => [$this->pdf('signed-form.pdf')],
        ])->assertSessionHas('status');

        $files = $ticket->attachments()->where('kind', RequestAttachment::KIND_SIGNED_EVIDENCE)->get();
        $this->assertCount(1, $files);
        $this->assertSame('signed-form.pdf', $files->first()->original_name);
        $this->assertSame(['status_change', 'authorization', 'attachment'], $ticket->statusLogs()->pluck('action')->all());
    }

    public function test_a_bad_signed_file_stops_the_approval_and_nothing_is_written(): void
    {
        $ticket = $this->ticket();
        $before = $this->logCount($ticket);

        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket), [
            'files' => [UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')],
        ])->assertSessionHasErrors(['files.0'], null, 'approve');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Submitted, $ticket->status);
        $this->assertNull($ticket->president_id);
        $this->assertSame($before, $this->logCount($ticket));
        $this->assertCount(0, $ticket->attachments()->get());
    }

    public function test_only_authorizers_can_approve_or_disapprove(): void
    {
        $ticket = $this->ticket();

        foreach (['requester', 'maintenance', 'finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->post(route('tickets.approve', $ticket))->assertForbidden();
            $this->actingAs($this->user($who))->post(route('tickets.disapprove', $ticket), ['reason' => 'x'])->assertForbidden();
        }
        $this->actingAs($this->user('requester2'))->post(route('tickets.approve', $ticket))->assertNotFound();

        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);
    }

    public function test_approving_in_the_wrong_status_gives_a_clear_error(): void
    {
        $ticket = $this->approved();
        $before = $this->logCount($ticket);

        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket))->assertSessionHas('error');

        $this->assertSame($before, $this->logCount($ticket));
    }

    public function test_disapproving_needs_a_reason_and_records_it(): void
    {
        $president = $this->user('president');
        $ticket = $this->ticket();
        $url = route('tickets.disapprove', $ticket);

        $this->actingAs($president)->post($url, ['reason' => ''])->assertSessionHasErrors('reason', null, 'disapprove');
        $this->actingAs($president)->post($url, ['reason' => '   '])->assertSessionHasErrors('reason', null, 'disapprove');
        $this->assertSame(TicketStatus::Submitted, $ticket->fresh()->status);

        $this->actingAs($president)->post($url, ['reason' => 'No budget this term.'])->assertSessionHas('status');

        $this->assertSame(TicketStatus::Disapproved, $ticket->fresh()->status);
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id, 'action' => 'authorization', 'to_status' => 'disapproved', 'note' => 'No budget this term.',
        ]);
    }

    public function test_the_workflow_itself_refuses_a_blank_disapproval_reason(): void
    {
        $ticket = $this->ticket();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('needs a reason');

        TicketWorkflow::disapprove($ticket, $this->user('president'), '  ');
    }

    public function test_only_authorizers_can_disapprove_in_the_workflow(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only the School President can disapprove a ticket.');

        TicketWorkflow::disapprove($this->ticket(), $this->user('maintenance'), 'x');
    }

    // ---- assign / reassign ----------------------------------------------------------------------

    public function test_admin_assigns_an_approved_ticket(): void
    {
        $ticket = $this->approved();
        $staff = $this->user('maintenance2');

        $this->actingAs($this->user('maintenance'))->post(route('tickets.assign', $ticket), ['assignee_id' => $staff->id])
            ->assertRedirect()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Assigned, $ticket->status);
        $this->assertEquals($staff->id, $ticket->assigned_to);
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id, 'action' => 'assignment', 'from_status' => 'approved', 'to_status' => 'assigned',
        ]);
    }

    public function test_only_admin_can_assign(): void
    {
        $ticket = $this->approved();
        $url = route('tickets.assign', $ticket);
        $body = ['assignee_id' => $this->user('maintenance')->id];

        foreach (['requester', 'president', 'finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->post($url, $body)->assertForbidden();
        }
        $this->actingAs($this->user('requester2'))->post($url, $body)->assertNotFound();

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_assigning_before_approval_gives_a_clear_error(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.assign', $ticket), ['assignee_id' => $this->user('maintenance2')->id])
            ->assertSessionHas('error');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_a_ticket_can_only_be_assigned_to_active_maintenance_staff(): void
    {
        $ticket = $this->approved();
        $admin = $this->user('maintenance');
        $url = route('tickets.assign', $ticket);

        $this->actingAs($admin)->post($url, ['assignee_id' => $this->user('requester')->id])->assertSessionHas('error');

        $inactive = $this->user('maintenance3');
        $inactive->update(['is_active' => false]);
        $this->actingAs($admin)->post($url, ['assignee_id' => $inactive->id])->assertSessionHas('error');

        $this->actingAs($admin)->post($url, [])->assertSessionHasErrors('assignee_id', null, 'assign');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_a_reassignment_needs_a_note(): void
    {
        $ticket = $this->assigned('maintenance');
        $admin = $this->user('maintenance');
        $second = $this->user('maintenance2');
        $url = route('tickets.assign', $ticket);

        $this->actingAs($admin)->post($url, ['assignee_id' => $second->id, 'assign_note' => ''])
            ->assertSessionHasErrors('assign_note', null, 'assign');
        $this->assertEquals($admin->id, $ticket->fresh()->assigned_to);

        $this->actingAs($admin)->post($url, ['assignee_id' => $second->id, 'assign_note' => 'First staff is on leave.'])
            ->assertSessionHas('status');

        $this->assertEquals($second->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('request_status_logs', ['request_id' => $ticket->id, 'action' => 'reassignment']);
    }

    public function test_a_staff_member_can_transfer_their_own_ticket_but_needs_a_reason(): void
    {
        $staff = $this->user('maintenance2');
        $staff->update(['role_id' => \App\Models\Role::where('slug', 'staff')->value('id')]);   // the Staff role (was the "staff only" tick box)
        $ticket = $this->assigned('maintenance2');     // the Head assigned it to maintenance2
        $target = $this->user('maintenance3');
        $url = route('tickets.assign', $ticket);

        $this->actingAs($staff)->post($url, ['assignee_id' => $target->id, 'assign_note' => ''])
            ->assertSessionHasErrors('assign_note', null, 'assign');
        $this->assertEquals($staff->id, $ticket->fresh()->assigned_to);

        $this->actingAs($staff)->post($url, ['assignee_id' => $target->id, 'assign_note' => 'I am on leave tomorrow.'])
            ->assertSessionHas('status');
        $this->assertEquals($target->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('request_status_logs', ['request_id' => $ticket->id, 'action' => 'reassignment']);
    }

    public function test_a_staff_member_cannot_assign_or_reassign_other_peoples_tickets(): void
    {
        $staff = $this->user('maintenance2');
        $staff->update(['role_id' => \App\Models\Role::where('slug', 'staff')->value('id')]);   // the Staff role (was the "staff only" tick box)
        $other = $this->user('maintenance3');

        $theirs = $this->assigned('maintenance3');
        $this->actingAs($staff)->post(route('tickets.assign', $theirs), ['assignee_id' => $staff->id, 'assign_note' => 'Mine now.'])
            ->assertForbidden();
        $this->assertEquals($other->id, $theirs->fresh()->assigned_to);

        $open = $this->approved();
        $this->actingAs($staff)->post(route('tickets.assign', $open), ['assignee_id' => $other->id])
            ->assertForbidden();
        $this->assertNull($open->fresh()->assigned_to);
    }

    public function test_the_workflow_itself_refuses_a_staff_member_who_assigns_to_someone_else(): void
    {
        $staff = $this->user('maintenance2');
        $staff->update(['role_id' => \App\Models\Role::where('slug', 'staff')->value('id')]);   // the Staff role (was the "staff only" tick box)
        $ticket = $this->approved();

        $this->expectException(DomainException::class);
        TicketWorkflow::assign($ticket, $this->user('maintenance3'), $staff);
    }

    public function test_the_requester_sees_that_a_ticket_was_reassigned_but_not_the_reason(): void
    {
        $ticket = $this->assigned('maintenance');

        $this->actingAs($this->user('maintenance'))->post(route('tickets.assign', $ticket), [
            'assignee_id' => $this->user('maintenance2')->id,
            'assign_note' => 'Private leave reason.',
        ])->assertSessionHas('status');

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))
            ->assertOk()->assertSee('Private leave reason.');

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))
            ->assertOk()->assertSee('Reassigned from')->assertDontSee('Private leave reason.');
    }

    public function test_the_assign_dropdown_lists_active_staff_with_their_open_ticket_count(): void
    {
        $this->assigned('maintenance2');                  // maintenance2 now holds 1 open ticket
        $this->user('maintenance3')->update(['is_active' => false]);
        $target = $this->approved();

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $target))->assertOk()
            ->assertSee('Test Maintenance Admin — 0 open tickets', false)
            ->assertSee('Test Maintenance Staff 2 — 1 open ticket', false)
            ->assertDontSee('Test Maintenance Staff 3');

        $staff = TicketActions::assignableStaff();
        $this->assertSame(['Test Maintenance Admin', 'Test Maintenance Staff 2'], $staff->pluck('name')->sort()->values()->all());
    }

    // ---- emergency ------------------------------------------------------------------------------

    public function test_flagging_an_emergency_needs_a_reason_and_moves_the_ticket_to_the_top(): void
    {
        $a = $this->ticketAt(now()->subHours(3));
        $b = $this->ticketAt(now()->subHours(2));
        $c = $this->ticketAt(now()->subHour());
        $admin = $this->user('maintenance');
        $url = route('tickets.emergency.flag', $c);

        $this->actingAs($admin)->post($url, ['emergency_reason' => ''])->assertSessionHasErrors('emergency_reason', null, 'flag');
        $this->assertFalse($c->fresh()->is_emergency);

        $this->actingAs($admin)->post($url, ['emergency_reason' => 'Water leak in the corridor'])->assertSessionHas('status');

        $c->refresh();
        $this->assertTrue($c->is_emergency);
        $this->assertSame('Water leak in the corridor', $c->emergency_reason);
        $this->assertEquals($admin->id, $c->emergency_flagged_by);
        $this->assertSame([$c->id, $a->id, $b->id], MaintenanceRequest::queueOrder()->pluck('id')->all());
        $this->actingAs($admin)->get(route('tickets.show', $c))->assertOk()->assertSee('Emergency');
    }

    public function test_clearing_the_flag_restores_the_original_queue_order(): void
    {
        $a = $this->ticketAt(now()->subHours(3));
        $b = $this->ticketAt(now()->subHours(2));
        $c = $this->ticketAt(now()->subHour());
        $admin = $this->user('maintenance');
        $submittedAt = $c->submitted_at;

        $this->actingAs($admin)->post(route('tickets.emergency.flag', $a), ['emergency_reason' => 'Live wire']);
        $this->assertSame([$a->id, $b->id, $c->id], MaintenanceRequest::queueOrder()->pluck('id')->all());

        $this->actingAs($admin)->post(route('tickets.emergency.flag', $c), ['emergency_reason' => 'Flooding']);
        $this->assertSame([$a->id, $c->id, $b->id], MaintenanceRequest::queueOrder()->pluck('id')->all());

        $this->actingAs($admin)->post(route('tickets.emergency.clear', $c), ['clear_note' => 'Fixed already.'])->assertSessionHas('status');
        $this->actingAs($admin)->post(route('tickets.emergency.clear', $a))->assertSessionHas('status');

        $this->assertSame([$a->id, $b->id, $c->id], MaintenanceRequest::queueOrder()->pluck('id')->all());
        $this->assertTrue($submittedAt->equalTo($c->fresh()->submitted_at));
        $this->assertFalse($c->fresh()->is_emergency);
        $this->assertNull($c->fresh()->emergency_reason);
    }

    public function test_only_authorized_roles_can_flag_or_clear_an_emergency(): void
    {
        $ticket = $this->ticket();
        $flag = route('tickets.emergency.flag', $ticket);

        foreach (['requester', 'finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->post($flag, ['emergency_reason' => 'Urgent'])->assertForbidden();
        }
        $this->actingAs($this->user('requester2'))->post($flag, ['emergency_reason' => 'Urgent'])->assertNotFound();
        $this->assertFalse($ticket->fresh()->is_emergency);

        // the President may flag (permission matrix) but not clear
        $this->actingAs($this->user('president'))->post($flag, ['emergency_reason' => 'Urgent'])->assertSessionHas('status');
        $this->assertTrue($ticket->fresh()->is_emergency);

        $clear = route('tickets.emergency.clear', $ticket);
        foreach (['requester', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who))->post($clear)->assertForbidden();
        }
        $this->assertTrue($ticket->fresh()->is_emergency);
    }

    public function test_flag_and_clear_in_the_wrong_state_give_a_clear_error(): void
    {
        $admin = $this->user('maintenance');
        $ticket = $this->ticket();

        // not flagged yet -> cannot clear
        $this->actingAs($admin)->post(route('tickets.emergency.clear', $ticket))->assertSessionHas('error');

        // already flagged -> cannot flag again
        $this->actingAs($admin)->post(route('tickets.emergency.flag', $ticket), ['emergency_reason' => 'Leak']);
        $this->actingAs($admin)->post(route('tickets.emergency.flag', $ticket), ['emergency_reason' => 'Leak again'])->assertSessionHas('error');
        $this->assertSame('Leak', $ticket->fresh()->emergency_reason);
    }

    // ---- notes ----------------------------------------------------------------------------------

    public function test_involved_people_can_add_a_note_and_view_only_roles_cannot(): void
    {
        $ticket = $this->assigned('maintenance2');
        $url = route('tickets.notes.store', $ticket);

        foreach (['requester', 'maintenance', 'maintenance2', 'president'] as $who) {
            $this->actingAs($this->user($who))->post($url, ['note' => "Note from {$who}"])->assertSessionHas('status');
            $this->assertDatabaseHas('request_status_logs', [
                'request_id' => $ticket->id, 'action' => 'note', 'note' => "Note from {$who}", 'user_id' => $this->user($who)->id,
            ]);
        }

        // finance and the department head can see the ticket but are view-only
        $this->actingAs($this->user('finance'))->post($url, ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->user('depthead'))->post($url, ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->user('requester2'))->post($url, ['note' => 'x'])->assertNotFound();
    }

    public function test_an_empty_note_is_refused(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user('requester'))->post(route('tickets.notes.store', $ticket), ['note' => '  '])
            ->assertSessionHasErrors('note', null, 'note');
    }

    // ---- terminal tickets -----------------------------------------------------------------------

    public function test_a_disapproved_ticket_accepts_no_further_actions(): void
    {
        $ticket = $this->disapproved();
        $before = $this->logCount($ticket);
        $admin = $this->user('maintenance');
        $president = $this->user('president');

        $this->actingAs($admin)->post(route('tickets.send-for-authorization', $ticket))->assertSessionHas('error');
        $this->actingAs($president)->post(route('tickets.approve', $ticket))->assertSessionHas('error');
        $this->actingAs($president)->post(route('tickets.disapprove', $ticket), ['reason' => 'again'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['assignee_id' => $admin->id])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('tickets.emergency.flag', $ticket), ['emergency_reason' => 'Leak'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('tickets.emergency.clear', $ticket))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('tickets.notes.store', $ticket), ['note' => 'late note'])->assertSessionHas('error');

        $this->assertSame(TicketStatus::Disapproved, $ticket->fresh()->status);
        $this->assertSame($before, $this->logCount($ticket));
        $this->assertSame([], TicketActions::for($ticket->fresh(), $admin));
        $this->assertSame([], TicketActions::for($ticket->fresh(), $president));
    }

    // ---- the Action panel -----------------------------------------------------------------------

    public function test_the_panel_shows_only_what_the_user_may_do_in_this_status(): void
    {
        $ticket = $this->ticket();                       // Submitted
        $page = fn (string $who) => $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk();

        $page('maintenance')
            ->assertSee(route('tickets.send-for-authorization', $ticket))
            ->assertSee(route('tickets.emergency.flag', $ticket))
            ->assertSee(route('tickets.notes.store', $ticket))
            ->assertDontSee(route('tickets.approve', $ticket))
            ->assertDontSee(route('tickets.assign', $ticket));

        $page('president')
            ->assertSee(route('tickets.approve', $ticket))
            ->assertSee(route('tickets.disapprove', $ticket))
            ->assertDontSee(route('tickets.send-for-authorization', $ticket))
            ->assertDontSee(route('tickets.emergency.clear', $ticket));

        $page('requester')
            ->assertSee(route('tickets.notes.store', $ticket))
            ->assertDontSee(route('tickets.approve', $ticket))
            ->assertDontSee(route('tickets.send-for-authorization', $ticket))
            ->assertDontSee(route('tickets.emergency.flag', $ticket));

        $page('finance')
            ->assertSee('nothing for you to do')
            ->assertDontSee(route('tickets.notes.store', $ticket));

        // once approved the panel changes: assign appears for Admin, approve is gone
        TicketWorkflow::authorize($ticket, $this->user('president'));
        $ticket->refresh();

        $page('maintenance')->assertSee(route('tickets.assign', $ticket))->assertDontSee(route('tickets.send-for-authorization', $ticket));
        $page('president')->assertDontSee(route('tickets.approve', $ticket));
    }

    public function test_the_panel_of_a_terminal_ticket_says_so(): void
    {
        $ticket = $this->disapproved();

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('No further actions are possible')
            ->assertDontSee(route('tickets.notes.store', $ticket))
            ->assertDontSee(route('tickets.emergency.flag', $ticket));
    }

    // ---- approvals inbox ------------------------------------------------------------------------

    public function test_the_approvals_inbox_lists_waiting_tickets_emergencies_first_then_oldest(): void
    {
        $a = $this->ticketAt(now()->subHours(3));                       // Submitted
        $b = $this->ticketAt(now()->subHours(2));
        TicketWorkflow::sendForAuthorization($b, $this->user('maintenance'));   // Pending Authorization
        $c = $this->ticketAt(now()->subHour());
        TicketWorkflow::flagEmergency($c, $this->user('maintenance'), 'Live wire');
        $approved = $this->ticketAt(now()->subHours(5));
        TicketWorkflow::authorize($approved, $this->user('president'));        // not waiting any more

        $president = $this->user('president');

        $this->assertSame([$c->id, $a->id, $b->id], $this->listedIds($president));
        $this->actingAs($president)->get(route('approvals.index'))->assertOk()
            ->assertSee($c->ticket_no)->assertDontSee($approved->ticket_no);
    }

    public function test_the_approvals_inbox_is_only_for_authorizers(): void
    {
        foreach (['requester', 'maintenance', 'finance', 'depthead', 'treasurer', 'custodian'] as $who) {
            $this->actingAs($this->user($who))->get(route('approvals.index'))->assertForbidden();
        }
    }

    public function test_approving_and_disapproving_from_the_inbox_stay_in_the_inbox(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $president = $this->user('president');

        $this->actingAs($president)->post(route('tickets.approve', $a), ['return' => 'approvals'])
            ->assertRedirect(route('approvals.index'));
        $this->actingAs($president)->post(route('tickets.disapprove', $b), ['return' => 'approvals', 'reason' => 'Duplicate'])
            ->assertRedirect(route('approvals.index'));

        $this->assertSame(TicketStatus::Approved, $a->fresh()->status);
        $this->assertSame(TicketStatus::Disapproved, $b->fresh()->status);
        $this->assertSame([], $this->listedIds($president));
    }

    public function test_the_president_dashboard_counts_and_links_to_the_inbox(): void
    {
        $this->ticket();
        $second = $this->ticket();
        TicketWorkflow::sendForAuthorization($second, $this->user('maintenance'));

        $this->actingAs($this->user('president'))->get(route('president.dashboard'))->assertOk()
            ->assertSee(route('approvals.index'));
    }

    // ---- lifecycle (front half) -----------------------------------------------------------------

    public function test_a_ticket_goes_from_submitted_to_assigned_through_the_ui_with_history(): void
    {
        $ticket = $this->ticket();
        $admin = $this->user('maintenance');
        $staff = $this->user('maintenance2');

        $this->actingAs($admin)->post(route('tickets.send-for-authorization', $ticket));
        $this->actingAs($this->user('president'))->post(route('tickets.approve', $ticket), ['remarks' => 'OK']);
        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['assignee_id' => $staff->id]);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Assigned, $ticket->status);
        $this->assertEquals($staff->id, $ticket->assigned_to);

        $logs = $ticket->statusLogs()->get();
        $this->assertSame(['status_change', 'status_change', 'authorization', 'assignment'], $logs->pluck('action')->all());
        $this->assertSame([null, 'submitted', 'pending_authorization', 'approved'], $logs->pluck('from_status')->all());
        $this->assertSame(['submitted', 'pending_authorization', 'approved', 'assigned'], $logs->pluck('to_status')->all());
        $this->assertEquals(
            [$this->user('requester')->id, $admin->id, $this->user('president')->id, $admin->id],
            $logs->pluck('user_id')->all()
        );
    }

    // ---- concurrency ----------------------------------------------------------------------------

    public function test_the_second_of_two_simultaneous_actions_gets_a_clear_message(): void
    {
        $ticket = $this->ticket();
        $president = $this->user('president');

        // two people opened the same ticket at the same moment
        $first = MaintenanceRequest::findOrFail($ticket->id);
        $second = MaintenanceRequest::findOrFail($ticket->id);

        TicketWorkflow::authorize($first, $president, 'Approved first.');

        try {
            TicketWorkflow::disapprove($second, $president, 'Disapproved second.');
            $this->fail('The second action should have been refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('was just updated', $e->getMessage());
        }

        $this->assertSame(TicketStatus::Approved, $ticket->fresh()->status);
        $this->assertSame(1, $ticket->statusLogs()->where('action', 'authorization')->count());
    }

    public function test_two_people_flagging_the_same_ticket_only_flag_it_once(): void
    {
        $ticket = $this->ticket();
        $admin = $this->user('maintenance');
        $first = MaintenanceRequest::findOrFail($ticket->id);
        $second = MaintenanceRequest::findOrFail($ticket->id);

        TicketWorkflow::flagEmergency($first, $admin, 'Leak');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('was just updated');

        TicketWorkflow::flagEmergency($second, $admin, 'Leak too');
    }

    public function test_deciding_an_already_decided_ticket_shows_a_flash_error(): void
    {
        // Over HTTP the request loads a fresh copy, so the second person meets the status rule;
        // it reaches the screen through the same channel as the "just updated" message (flash error).
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));
        $before = $this->logCount($ticket);

        $this->actingAs($this->user('president'))
            ->post(route('tickets.disapprove', $ticket), ['reason' => 'Too late'])
            ->assertSessionHas('error');
        $this->actingAs($this->user('president'))
            ->post(route('tickets.approve', $ticket))
            ->assertSessionHas('error');

        $this->assertSame(TicketStatus::Approved, $ticket->fresh()->status);
        $this->assertSame($before, $this->logCount($ticket));
    }
}
