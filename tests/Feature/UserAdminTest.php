<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\UserController;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Phase 6: user / team management (manage_users, temp password, activate, reset, role + department, audit). */
class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function admin(): User
    {
        return $this->user('maintenance');
    }

    private function newUserData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@lcctanauan.edu.ph',
            'employee_no' => 'E-1001',
            'role_id' => Role::where('slug', Role::REQUESTER)->value('id'),
            'department_id' => Department::where('code', 'HS')->value('id'),
        ];
    }

    private function auditFor(string $action, User $subject): ?AuditLog
    {
        return AuditLog::where('action', $action)->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->id)->latest('id')->first();
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_only_the_maintenance_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users.index'))->assertOk()->assertSee('Add a user');

        foreach (['requester', 'finance', 'president', 'depthead', 'treasurer', 'custodian'] as $name) {
            $this->actingAs($this->user($name))->get(route('admin.users.index'))->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_everyone_else_gets_403_on_every_action(): void
    {
        $target = $this->user('requester2');

        foreach (['requester', 'finance', 'president'] as $name) {
            $this->actingAs($this->user($name));

            $this->post(route('admin.users.store'), $this->newUserData())->assertForbidden();
            $this->put(route('admin.users.update', $target), ['role_id' => $target->role_id])->assertForbidden();
            $this->patch(route('admin.users.status', $target), ['is_active' => 0])->assertForbidden();
            $this->post(route('admin.users.reset-password', $target))->assertForbidden();
        }

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@lcctanauan.edu.ph']);
    }

    public function test_the_sidebar_link_is_for_the_admin_only(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users.index'))->assertSee(route('admin.users.index'));
        $this->actingAs($this->user('requester'))->get(route('requester.dashboard'))->assertDontSee(route('admin.users.index'));
    }

    // ---- list + search --------------------------------------------------------------------------

    public function test_search_filters_by_name_email_role_and_status(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'Test Treasurer']))
            ->assertOk()->assertSee('treasurer@lcctanauan.edu.ph')->assertDontSee('finance@lcctanauan.edu.ph');

        $this->get(route('admin.users.index', ['q' => 'custodian@']))
            ->assertSee('Test Custodian')->assertDontSee('Test Treasurer');

        $this->get(route('admin.users.index', ['role' => Role::where('slug', Role::FINANCE)->value('id')]))
            ->assertSee('Test Finance')->assertDontSee('Test Custodian');

        $this->user('finance')->update(['is_active' => false]);

        $this->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertSee('Test Finance')->assertDontSee('Test Custodian');
        $this->get(route('admin.users.index', ['status' => 'active']))
            ->assertSee('Test Custodian')->assertDontSee('Test Finance');
    }

    public function test_a_percent_sign_in_the_search_is_just_text(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users.index', ['q' => '%']))
            ->assertOk()->assertSee('No users match your search.');
    }

    // ---- add ------------------------------------------------------------------------------------

    public function test_adding_a_user_makes_a_temporary_password_and_forces_a_change(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), $this->newUserData());

        $response->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'maria.santos@lcctanauan.edu.ph')->firstOrFail();
        $temp = session('temp_password');

        $this->assertSame('Maria Santos', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('E-1001', $user->employee_no);
        $this->assertSame($user->email, $temp['email']);
        $this->assertSame(12, strlen($temp['password']));
        $this->assertTrue(Hash::check($temp['password'], $user->password));
        $this->assertNotSame($temp['password'], $user->password);
    }

    public function test_the_temporary_password_is_shown_once_and_never_logged(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), $this->newUserData());
        $temp = session('temp_password');

        $this->get(route('admin.users.index'))->assertSee($temp['password']);
        $this->get(route('admin.users.index'))->assertDontSee($temp['password']);

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($temp['password'], $log->description.json_encode($log->meta));
        }
    }

    public function test_the_new_user_must_change_the_temporary_password_first(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), $this->newUserData());
        $temp = session('temp_password');

        auth()->logout();

        $this->post('/login', ['email' => $temp['email'], 'password' => $temp['password']])->assertRedirect();
        $this->get(route('requester.dashboard'))->assertRedirect(route('password.force'));
    }

    public function test_adding_a_user_is_audited(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), $this->newUserData());

        $user = User::where('email', 'maria.santos@lcctanauan.edu.ph')->firstOrFail();
        $log = $this->auditFor('user_created', $user);

        $this->assertNotNull($log);
        $this->assertSame($this->admin()->id, $log->user_id);
    }

    public function test_adding_checks_email_domain_duplicates_and_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.store'), $this->newUserData(['email' => 'maria@gmail.com']))
            ->assertSessionHasErrors('email');

        $this->post(route('admin.users.store'), $this->newUserData(['email' => 'requester@lcctanauan.edu.ph']))
            ->assertSessionHasErrors('email');

        $this->post(route('admin.users.store'), $this->newUserData(['role_id' => 9999]))
            ->assertSessionHasErrors('role_id');

        $this->post(route('admin.users.store'), $this->newUserData(['department_id' => 9999]))
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('users', ['name' => 'Maria Santos']);
    }

    public function test_the_email_is_saved_in_lower_case(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), $this->newUserData(['email' => '  Maria.Santos@LCCTanauan.edu.ph ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'maria.santos@lcctanauan.edu.ph']);
    }

    // ---- activate / deactivate ------------------------------------------------------------------

    public function test_deactivating_and_reactivating_is_audited(): void
    {
        $target = $this->user('requester2');

        $this->actingAs($this->admin())->patch(route('admin.users.status', $target), ['is_active' => 0])
            ->assertSessionHas('status');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertNotNull($this->auditFor('user_deactivated', $target));

        $this->patch(route('admin.users.status', $target), ['is_active' => 1]);

        $this->assertTrue($target->fresh()->is_active);
        $this->assertNotNull($this->auditFor('user_reactivated', $target));
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.status', $admin), ['is_active' => 0])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertNull($this->auditFor('user_deactivated', $admin));
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $target = $this->user('requester2');
        $this->actingAs($this->admin())->patch(route('admin.users.status', $target), ['is_active' => 0]);

        auth()->logout();

        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivating_ends_the_open_sessions(): void
    {
        config(['session.driver' => 'database']);

        $target = $this->user('requester2');

        \DB::table('sessions')->insert([
            'id' => 'abc123', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->actingAs($this->admin())->patch(route('admin.users.status', $target), ['is_active' => 0]);

        $this->assertDatabaseMissing('sessions', ['id' => 'abc123']);
    }

    public function test_the_status_value_is_required(): void
    {
        $this->actingAs($this->admin())->patch(route('admin.users.status', $this->user('requester2')), [])
            ->assertSessionHasErrors('is_active');
    }

    // ---- reset password -------------------------------------------------------------------------

    public function test_reset_password_makes_a_new_temporary_password(): void
    {
        $target = $this->user('requester2');

        $this->actingAs($this->admin())->post(route('admin.users.reset-password', $target))
            ->assertSessionHas('status');

        $target->refresh();
        $temp = session('temp_password');

        $this->assertTrue((bool) $target->must_change_password);
        $this->assertFalse(Hash::check('password', $target->password));
        $this->assertTrue(Hash::check($temp['password'], $target->password));
    }

    public function test_reset_password_is_audited_without_the_password(): void
    {
        $target = $this->user('requester2');

        $this->actingAs($this->admin())->post(route('admin.users.reset-password', $target));

        $log = $this->auditFor('user_password_reset', $target);

        $this->assertNotNull($log);
        $this->assertSame($this->admin()->id, $log->user_id);
        $this->assertStringNotContainsString(session('temp_password')['password'], $log->description.json_encode($log->meta));
    }

    public function test_you_cannot_reset_your_own_password_here(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.reset-password', $admin))->assertSessionHas('error');

        $this->assertFalse((bool) $admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_temporary_passwords_are_strong_and_different_each_time(): void
    {
        $seen = [];

        for ($i = 0; $i < 30; $i++) {
            $p = UserController::temporaryPassword();

            $this->assertSame(12, strlen($p));
            $this->assertMatchesRegularExpression('/[A-Z]/', $p);
            $this->assertMatchesRegularExpression('/[a-z]/', $p);
            $this->assertMatchesRegularExpression('/[0-9]/', $p);
            $this->assertDoesNotMatchRegularExpression('/[0OIl1]/', $p);
            $seen[$p] = true;
        }

        $this->assertGreaterThan(25, count($seen));
    }

    // ---- role + department ----------------------------------------------------------------------

    public function test_changing_the_role_is_audited(): void
    {
        $target = $this->user('requester2');
        $finance = Role::where('slug', Role::FINANCE)->value('id');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), ['role_id' => $finance, 'department_id' => $target->department_id])
            ->assertSessionHas('status');

        $this->assertSame(Role::FINANCE, $target->fresh()->role->slug);

        $log = $this->auditFor('role_changed', $target);
        $this->assertNotNull($log);
        $this->assertSame(Role::REQUESTER, $log->meta['from_role']);
        $this->assertSame(Role::FINANCE, $log->meta['to_role']);
    }

    public function test_changing_the_department_is_audited(): void
    {
        $target = $this->user('requester2');
        $elem = Department::where('code', 'ELEM')->value('id');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), ['role_id' => $target->role_id, 'department_id' => $elem]);

        $this->assertSame($elem, $target->fresh()->department_id);
        $this->assertNotNull($this->auditFor('user_updated', $target));
    }

    public function test_the_department_can_be_cleared(): void
    {
        $target = $this->user('requester2');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), ['role_id' => $target->role_id, 'department_id' => '']);

        $this->assertNull($target->fresh()->department_id);
    }

    public function test_saving_without_changes_writes_no_audit_row(): void
    {
        $target = $this->user('requester2');
        $before = AuditLog::count();

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), ['role_id' => $target->role_id, 'department_id' => $target->department_id])
            ->assertSessionHas('status');

        $this->assertSame($before, AuditLog::count());
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        $admin = $this->admin();
        $finance = Role::where('slug', Role::FINANCE)->value('id');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), ['role_id' => $finance, 'department_id' => $admin->department_id])
            ->assertSessionHas('error');

        $this->assertSame(Role::MAINTENANCE_ADMIN, $admin->fresh()->role->slug);
        $this->assertNull($this->auditFor('role_changed', $admin));
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $target = $this->user('requester2');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), ['role_id' => 9999])
            ->assertSessionHasErrors('role_id');

        $this->assertSame(Role::REQUESTER, $target->fresh()->role->slug);
    }
}
