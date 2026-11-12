<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 4A: roles and config for department-scoped access.
 * Only the foundation: which roles exist, which are scoped / global, who may open the work screens.
 * Ticket visibility (4B) and claim / assign / transfer rules (4C) are tested in their own phases.
 */
class DepartmentScopedRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
    }

    private function make(string $slug, ?int $departmentId = null): User
    {
        return User::create([
            'name' => 'Test '.$slug,
            'email' => $slug.'-'.uniqid().'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $departmentId,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function dept(string $code): int
    {
        return (int) Department::where('code', $code)->value('id');
    }

    public function test_system_admin_role_exists_and_staff_and_head_are_relabelled(): void
    {
        $this->assertSame('System Administrator', Role::where('slug', Role::SYSTEM_ADMIN)->value('name'));
        $this->assertSame('Department Staff', Role::where('slug', Role::STAFF)->value('name'));
        $this->assertSame('Department Head', Role::where('slug', Role::HEAD)->value('name'));
    }

    public function test_scoped_and_global_roles_come_from_config_not_code(): void
    {
        $this->assertEqualsCanonicalizing(['staff', 'head', 'maintenance_admin'], Role::departmentScopedSlugs());
        $this->assertSame(['system_admin'], Role::globalSlugs());

        config(['school.department_scoped_roles' => ['staff'], 'school.global_roles' => ['head']]);

        $this->assertSame(['staff'], Role::departmentScopedSlugs());
        $this->assertSame(['head'], Role::globalSlugs());
        $this->assertTrue($this->make('head')->isGlobalRole());
        $this->assertFalse($this->make('maintenance_admin', $this->dept('FIN'))->isDepartmentScoped());
    }

    public function test_staff_head_and_maintenance_admin_are_scoped_to_their_own_department(): void
    {
        $finance = $this->dept('FIN');
        $registrar = $this->dept('REG');
        $this->assertNotSame(0, $finance);
        $this->assertNotSame($finance, $registrar);

        foreach (['staff', 'head', 'maintenance_admin'] as $slug) {
            $user = $this->make($slug, $finance);
            $this->assertTrue($user->isDepartmentScoped(), $slug);
            $this->assertFalse($user->isGlobalRole(), $slug);
            $this->assertSame($finance, $user->scopedDepartmentId(), $slug);
            $this->assertNotSame($registrar, $user->scopedDepartmentId(), $slug);
        }
    }

    public function test_a_scoped_user_without_a_department_has_no_scope_to_act_in(): void
    {
        $this->assertNull($this->make('staff', null)->scopedDepartmentId());
    }

    public function test_system_admin_is_global_and_ignores_its_department(): void
    {
        $admin = $this->make('system_admin', $this->dept('FIN'));

        $this->assertTrue($admin->isSystemAdmin());
        $this->assertTrue($admin->isGlobalRole());
        $this->assertFalse($admin->isDepartmentScoped());
        $this->assertNull($admin->scopedDepartmentId());
    }

    public function test_requesters_are_neither_scoped_nor_global(): void
    {
        $requester = $this->make('requester', $this->dept('FIN'));

        $this->assertFalse($requester->isDepartmentScoped());
        $this->assertFalse($requester->isGlobalRole());
        $this->assertNull($requester->scopedDepartmentId());
    }

    public function test_system_admin_has_the_global_capabilities_and_a_requester_has_none(): void
    {
        $admin = $this->make('system_admin');

        foreach (['view_all_tickets', 'assign', 'manage_users', 'manage_settings', 'view_audit_logs'] as $capability) {
            $this->assertTrue($admin->can_do($capability), $capability);
        }

        $this->assertFalse($this->make('requester')->can_do('manage_users'));
        $this->assertFalse($this->make('staff', $this->dept('FIN'))->can_do('manage_users'));
    }

    public function test_work_screens_are_open_to_the_work_roles_and_closed_to_the_others(): void
    {
        $this->assertEqualsCanonicalizing(
            ['staff', 'head', 'maintenance_admin', 'system_admin'],
            Role::workQueueSlugs()
        );

        foreach (['staff', 'head', 'maintenance_admin', 'system_admin'] as $slug) {
            $user = $this->make($slug, $slug === 'system_admin' ? null : $this->dept('FIN'));
            $this->assertSame('maintenance.dashboard', $user->dashboardRoute(), $slug);
            $this->actingAs($user)->get(route('maintenance.board'))->assertOk();
            $this->actingAs($user)->get(route('maintenance.dashboard'))->assertOk();
        }

        $this->actingAs($this->make('requester', $this->dept('FIN')))->get(route('maintenance.board'))->assertForbidden();
        $this->actingAs($this->make('finance'))->get(route('maintenance.board'))->assertForbidden();
    }

    public function test_system_admin_is_not_a_ticket_worker_and_gets_no_maintenance_mail(): void
    {
        $this->assertNotContains('system_admin', Role::maintenanceSlugs());
        $this->assertFalse($this->make('system_admin')->isMaintenanceTeam());
    }
}
