<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5: department management (admin).
 *
 *  - CRUD + capability (403 for everyone except the System Administrator)
 *  - deactivate rules: open tickets need a transfer target; finished tickets stay
 *  - hard delete is refused when any ticket exists
 *  - inactive departments disappear from the ticket / registration forms and from new user assignments
 *  - AuditLogger entries for every department change
 *  - Head / Staff assignment through Admin/UserController
 */
class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    private function admin(): User
    {
        return $this->user('sysadmin');
    }

    private function department(string $code): Department
    {
        return Department::where('code', $code)->firstOrFail();
    }

    private function makeUser(string $slug, ?Department $department = null): User
    {
        return User::create([
            'name' => 'Test '.$slug,
            'email' => $slug.'-'.uniqid().'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $department?->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /** A ticket POINTING AT $dept (filed by $filedBy, received by $dept). */
    private function ticket(Department $dept, string $status = TicketStatus::Submitted->value, ?Department $filedBy = null): MaintenanceRequest
    {
        return MaintenanceRequest::create([
            'requester_id' => $this->user('requester')->id,
            'department_id' => ($filedBy ?? $dept)->id,
            'assigned_department_id' => $dept->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Room 101',
            'description' => 'Test ticket for '.$dept->code,
            'status' => $status,
        ]);
    }

    private function formData(Department $department): array
    {
        return [
            'department_id' => $department->id,
            'category_id' => Category::visible()->ofDepartment(Department::maintenanceId())->firstOrFail()->id,
            'location' => 'Room 101',
            'job_description' => 'Broken light',
            'reason' => 'The room is dark.',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ];
    }

    private function audit(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->firstOrFail();
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_only_the_system_administrator_can_open_the_department_pages(): void
    {
        $dept = $this->department('REG');

        $this->actingAs($this->admin())->get(route('admin.departments.index'))
            ->assertOk()->assertSee('Add a department');
        $this->actingAs($this->admin())->get(route('admin.departments.create'))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.departments.edit', $dept))->assertOk();

        // Even the Maintenance Admin (who has manage_users / manage_categories) is refused.
        foreach (['maintenance', 'requester', 'finance', 'president', 'depthead', 'custodian'] as $name) {
            $this->actingAs($this->user($name));

            $this->get(route('admin.departments.index'))->assertForbidden();
            $this->get(route('admin.departments.create'))->assertForbidden();
            $this->get(route('admin.departments.edit', $dept))->assertForbidden();
            $this->post(route('admin.departments.store'), ['name' => 'Hacked Office'])->assertForbidden();
            $this->put(route('admin.departments.update', $dept), ['name' => 'Hacked Office'])->assertForbidden();
            $this->patch(route('admin.departments.deactivate', $dept))->assertForbidden();
            $this->patch(route('admin.departments.activate', $dept))->assertForbidden();
            $this->delete(route('admin.departments.destroy', $dept))->assertForbidden();
        }

        $this->assertDatabaseMissing('departments', ['name' => 'Hacked Office']);
        $this->assertTrue($dept->fresh()->is_active);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('admin.departments.index'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_departments_only_for_the_system_administrator(): void
    {
        $this->actingAs($this->admin())->get(route('tickets.index'))
            ->assertOk()->assertSee('admin/departments', false);

        $this->actingAs($this->user('maintenance'))->get(route('tickets.index'))
            ->assertOk()->assertDontSee('admin/departments', false);
    }

    // ---- CRUD -----------------------------------------------------------------------------------

    public function test_a_department_can_be_added_edited_and_deleted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'name' => 'Safety Office',
                'code' => 'saf',
                'description' => 'Handles campus safety requests.',
                'is_service_department' => '1',
            ])->assertSessionHasNoErrors();

        $dept = Department::where('code', 'SAF')->firstOrFail();   // codes are upper-cased
        $this->assertSame('Safety Office', $dept->name);
        $this->assertTrue($dept->is_active);
        $this->assertTrue($dept->is_service_department);
        $this->assertSame(1, AuditLog::where('action', AuditLogger::DEPARTMENT_CREATED)->count());

        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $dept), [
                'name' => 'Campus Safety',
                'code' => 'SAF',
                'description' => 'Updated description.',
                'is_service_department' => '0',
            ])->assertSessionHasNoErrors();

        $dept->refresh();
        $this->assertSame('Campus Safety', $dept->name);
        $this->assertSame('Updated description.', $dept->description);
        $this->assertFalse($dept->is_service_department);
        $this->assertTrue($dept->is_active);   // never changeable from the edit form
        $this->assertSame(1, AuditLog::where('action', AuditLogger::DEPARTMENT_UPDATED)->count());

        $this->actingAs($this->admin())
            ->delete(route('admin.departments.destroy', $dept))->assertSessionHas('status');
        $this->assertDatabaseMissing('departments', ['id' => $dept->id]);
    }

    public function test_the_name_and_code_must_be_unique(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), ['name' => 'Finance', 'code' => 'FIN'])
            ->assertSessionHasErrors(['name', 'code']);

        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $this->department('REG')), ['name' => 'Finance', 'code' => 'FIN'])
            ->assertSessionHasErrors(['name', 'code']);

        // Its own current values are fine.
        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $this->department('REG')), ['name' => 'Registrar', 'code' => 'REG'])
            ->assertSessionHasNoErrors();
    }

    public function test_an_active_head_can_be_assigned_and_staff_can_be_assigned_to_a_department(): void
    {
        $reg = $this->department('REG');
        $head = $this->makeUser(Role::HEAD, $reg);
        $staff = $this->makeUser(Role::STAFF, $this->department('FIN'));

        // The department's head pointer.
        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $reg), [
                'name' => $reg->name,
                'code' => $reg->code,
                'head_user_id' => $head->id,
            ])->assertSessionHasNoErrors();
        $this->assertSame($head->id, (int) $reg->fresh()->head_user_id);

        // A head from ANOTHER department is refused.
        $foreignHead = $this->makeUser(Role::HEAD, $this->department('FIN'));
        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $reg), [
                'name' => $reg->name,
                'code' => $reg->code,
                'head_user_id' => $foreignHead->id,
            ])->assertSessionHasErrors('head_user_id');
        $this->assertSame($head->id, (int) $reg->fresh()->head_user_id);

        // Staff assignment still goes through the existing Admin > Users controller.
        $staffRole = Role::where('slug', Role::STAFF)->firstOrFail();
        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $staff), [
                'role_id' => $staffRole->id,
                'department_id' => $reg->id,
            ])->assertSessionHas('status');
        $this->assertSame($reg->id, (int) $staff->fresh()->department_id);
    }

    // ---- deactivate rules -----------------------------------------------------------------------

    public function test_deactivating_is_blocked_while_there_are_open_tickets(): void
    {
        $reg = $this->department('REG');
        $ticket = $this->ticket($reg);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg))
            ->assertSessionHas('error');

        $this->assertTrue($reg->fresh()->is_active);
        $this->assertSame($reg->id, (int) $ticket->fresh()->assigned_department_id);
        $this->assertSame(0, AuditLog::where('action', AuditLogger::DEPARTMENT_DEACTIVATED)->count());
    }

    public function test_deactivating_with_a_transfer_target_moves_only_open_tickets(): void
    {
        $reg = $this->department('REG');
        $target = $this->department('MNT');
        $open = $this->ticket($reg);
        $finished = $this->ticket($reg, TicketStatus::Disapproved->value);
        $filedOpen = $this->ticket($target, TicketStatus::Submitted->value, $reg);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg), ['transfer_to' => $target->id])
            ->assertSessionHas('status');

        $this->assertFalse($reg->fresh()->is_active);
        $this->assertSame($target->id, (int) $open->fresh()->assigned_department_id);
        $this->assertSame($target->id, (int) $filedOpen->fresh()->department_id);

        // Finished tickets keep the old department for history.
        $this->assertSame($reg->id, (int) $finished->fresh()->assigned_department_id);

        $log = $this->audit(AuditLogger::DEPARTMENT_DEACTIVATED);
        $this->assertSame($target->id, $log->meta['transfer_to']);
        $this->assertSame(2, $log->meta['open_tickets']);
    }

    public function test_the_transfer_target_must_be_a_different_active_department(): void
    {
        $reg = $this->department('REG');
        $this->ticket($reg);
        $inactive = $this->department('GUID');
        $inactive->update(['is_active' => false]);

        // Same department.
        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg), ['transfer_to' => $reg->id])
            ->assertSessionHas('error');

        // Inactive department.
        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg), ['transfer_to' => $inactive->id])
            ->assertSessionHasErrors('transfer_to');

        $this->assertTrue($reg->fresh()->is_active);
    }

    public function test_a_department_with_only_finished_tickets_can_be_deactivated_without_a_transfer(): void
    {
        $reg = $this->department('REG');
        $finished = $this->ticket($reg, TicketStatus::Disapproved->value);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg))
            ->assertSessionHas('status');

        $this->assertFalse($reg->fresh()->is_active);
        $this->assertSame($reg->id, (int) $finished->fresh()->assigned_department_id);
    }

    public function test_activation_makes_the_department_available_again_and_is_logged(): void
    {
        $reg = $this->department('REG');

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $reg))->assertSessionHas('status');
        $this->assertFalse($reg->fresh()->is_active);

        $this->actingAs($this->admin())
            ->patch(route('admin.departments.activate', $reg))->assertSessionHas('status');
        $this->assertTrue($reg->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', AuditLogger::DEPARTMENT_ACTIVATED)->count());
    }

    public function test_a_department_with_tickets_cannot_be_hard_deleted(): void
    {
        $reg = $this->department('REG');
        $this->ticket($reg, TicketStatus::Disapproved->value);   // even a FINISHED ticket blocks it

        $this->actingAs($this->admin())
            ->delete(route('admin.departments.destroy', $reg))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', ['id' => $reg->id]);
        $this->assertSame(0, AuditLog::where('action', AuditLogger::DEPARTMENT_DELETED)->count());
    }

    // ---- inactive departments on the forms ------------------------------------------------------

    public function test_inactive_departments_are_not_offered_on_new_ticket_or_registration_forms(): void
    {
        $guid = $this->department('GUID');
        $this->actingAs($this->admin())
            ->patch(route('admin.departments.deactivate', $guid))->assertSessionHas('status');

        // Ticket form: the option is gone.
        $this->actingAs($this->user('requester'))->get(route('repairs.create'))
            ->assertOk()->assertDontSee('>Guidance</option>', false);

        // A hand-made POST is refused by StoreRepairRequestRequest.
        $this->actingAs($this->user('requester'))
            ->post(route('repairs.store'), $this->formData($guid))
            ->assertSessionHasErrors('department_id');

        // Registration form is a guest page; leave the requester session first.
        Auth::logout();
        config()->set('school.allow_public_registration', true);
        $this->get(route('register'))->assertOk()->assertDontSee('>Guidance</option>', false);
        $this->post(route('register'), [
            'name' => 'New Person',
            'email' => 'new.person@lcctanauan.edu.ph',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
            'department_id' => $guid->id,
        ])->assertSessionHasErrors('department_id');

        // Admin > Users cannot assign an inactive department to a NEW account.
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'New Staff',
                'email' => 'new.staff@lcctanauan.edu.ph',
                'role_id' => Role::where('slug', Role::STAFF)->value('id'),
                'department_id' => $guid->id,
            ])->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('users', ['email' => 'new.staff@lcctanauan.edu.ph']);
    }

    // ---- audit ----------------------------------------------------------------------------------

    public function test_every_department_change_has_an_audit_entry(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), ['name' => 'Testing Office', 'code' => 'TST']);
        $dept = Department::where('code', 'TST')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $dept), ['name' => 'Testing Office 2', 'code' => 'TST']);
        $this->actingAs($this->admin())->patch(route('admin.departments.deactivate', $dept));
        $this->actingAs($this->admin())->patch(route('admin.departments.activate', $dept));
        $this->actingAs($this->admin())->delete(route('admin.departments.destroy', $dept));

        foreach ([
            AuditLogger::DEPARTMENT_CREATED,
            AuditLogger::DEPARTMENT_UPDATED,
            AuditLogger::DEPARTMENT_DEACTIVATED,
            AuditLogger::DEPARTMENT_ACTIVATED,
            AuditLogger::DEPARTMENT_DELETED,
        ] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }

        $updated = $this->audit(AuditLogger::DEPARTMENT_UPDATED);
        $this->assertContains('name', $updated->meta['changed']);
        $this->assertSame('Testing Office', $updated->meta['before']['name']);
    }
}
