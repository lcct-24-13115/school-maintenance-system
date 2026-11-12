<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\TicketActions;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff and Head roles (reference system: Requester < Staff < Head < Admin).
 * Staff = does the work, assigns only to themselves. Head = Staff + assigns anyone, authorizes, closes, reports, audit, FAQ.
 * Maintenance Admin = Head + users, settings, categories, QR codes, e-mail log.
 */
class StaffHeadRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
    }

    private function make(string $slug): User
    {
        return User::updateOrCreate(['email' => $slug.'-roles@lcctanauan.edu.ph'], [
            'name' => 'Test '.$slug,
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_the_two_new_roles_exist_and_the_maintenance_team_is_three_roles(): void
    {
        $this->assertSame('Maintenance Staff', Role::where('slug', Role::STAFF)->value('name'));
        $this->assertSame('Head of Maintenance', Role::where('slug', Role::HEAD)->value('name'));
        $this->assertSame('Maintenance Admin', Role::where('slug', Role::MAINTENANCE_ADMIN)->value('name'));
        $this->assertEqualsCanonicalizing(['staff', 'head', 'maintenance_admin'], Role::maintenanceSlugs());
    }

    public function test_every_maintenance_role_is_on_the_maintenance_team_and_goes_to_the_board(): void
    {
        foreach (Role::maintenanceSlugs() as $slug) {
            $user = $this->make($slug);
            $this->assertTrue($user->isMaintenanceTeam(), $slug);
            $this->assertSame('maintenance.dashboard', $user->dashboardRoute(), $slug);
            $this->actingAs($user)->get(route('maintenance.board'))->assertOk();
        }

        foreach ([Role::REQUESTER, Role::FINANCE, Role::CUSTODIAN] as $slug) {
            $this->assertFalse($this->make($slug)->isMaintenanceTeam(), $slug);
            $this->actingAs($this->make($slug))->get(route('maintenance.board'))->assertForbidden();
        }
    }

    public function test_only_the_staff_role_is_limited_to_its_own_tickets(): void
    {
        $this->assertTrue($this->make('staff')->isStaffOnly());
        $this->assertFalse($this->make('head')->isStaffOnly());
        $this->assertFalse($this->make('maintenance_admin')->isStaffOnly());
    }

    public function test_the_permission_split_between_staff_head_and_admin(): void
    {
        $staff = $this->make('staff');
        $head = $this->make('head');
        $admin = $this->make('maintenance_admin');

        // what only the Admin can do
        foreach (['manage_users', 'manage_settings', 'manage_categories', 'manage_qr_codes', 'view_email_log'] as $cap) {
            $this->assertTrue($admin->can_do($cap), "admin {$cap}");
            $this->assertFalse($head->can_do($cap), "head {$cap}");
            $this->assertFalse($staff->can_do($cap), "staff {$cap}");
        }

        // what the Head adds on top of Staff
        foreach (['view_confidential', 'clear_emergency', 'close', 'escalate', 'view_analytics', 'export_reports', 'view_audit_logs', 'manage_faq', 'manage_pm_schedules'] as $cap) {
            $this->assertTrue($head->can_do($cap), "head {$cap}");
            $this->assertFalse($staff->can_do($cap), "staff {$cap}");
        }

        // what Staff can do
        foreach (['view_all_tickets', 'work', 'assign', 'request_info', 'add_attachment', 'view_pm_schedules', 'cancel_own', 'reopen_own'] as $cap) {
            $this->assertTrue($staff->can_do($cap), "staff {$cap}");
        }
    }

    public function test_head_and_admin_authorize_but_staff_does_not(): void
    {
        $this->assertTrue($this->make('head')->canAuthorize());
        $this->assertTrue($this->make('maintenance_admin')->canAuthorize());
        $this->assertFalse($this->make('staff')->canAuthorize());
    }

    public function test_pages_follow_the_permissions(): void
    {
        $staff = $this->make('staff');
        $head = $this->make('head');
        $admin = $this->make('maintenance_admin');

        // Users page: Admin only
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($head)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();

        // Audit log: Head and Admin, not Staff
        $this->actingAs($admin)->get(route('admin.audit-logs.index'))->assertOk();
        $this->actingAs($head)->get(route('admin.audit-logs.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.audit-logs.index'))->assertForbidden();

        // E-mail log: Admin only
        $this->actingAs($admin)->get(route('admin.email-log.index'))->assertOk();
        $this->actingAs($head)->get(route('admin.email-log.index'))->assertForbidden();
    }

    public function test_all_three_can_be_assigned_a_ticket_and_the_admin_can_pick_the_new_roles_on_the_users_page(): void
    {
        $this->make('staff');
        $this->make('head');
        $this->make('maintenance_admin');

        $this->assertCount(3, TicketActions::assignableStaff());

        $admin = $this->make('maintenance_admin');
        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()->assertSee('Maintenance Staff')->assertSee('Head of Maintenance')->assertSee('Maintenance Admin');
    }

    public function test_the_staff_only_tick_box_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'staff_only'));
    }
}

