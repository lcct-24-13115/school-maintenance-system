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

/** Only the chosen roles see and may file the Facilities & Equipment form and the Requisition Slip. */
class FormAccessRolesTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->dept = Department::query()->orderBy('id')->firstOrFail();
    }

    private function make(string $slug): User
    {
        return User::updateOrCreate(['email' => "{$slug}-access@lcctanauan.edu.ph"], [
            'name' => "Access {$slug}",
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $this->dept->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_students_staff_and_maintenance_admin_cannot_open_or_file_the_forms(): void
    {
        foreach ([Role::REQUESTER, Role::STAFF, Role::MAINTENANCE_ADMIN] as $slug) {
            $user = $this->make($slug);

            foreach (['facilities.create', 'facilities.index', 'requisitions.create', 'requisitions.index'] as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden();
            }

            $this->actingAs($user)->post(route('facilities.store'), [])->assertForbidden();
            $this->actingAs($user)->post(route('requisitions.store'), [])->assertForbidden();
        }
    }

    public function test_the_chosen_roles_can_open_the_forms(): void
    {
        foreach ([Role::DEPARTMENT_HEAD, Role::CUSTODIAN, Role::PRESIDENT, Role::TREASURER, Role::FINANCE, Role::VICE_PRINCIPAL, Role::SYSTEM_ADMIN] as $slug) {
            $user = $this->make($slug);

            $this->actingAs($user)->get(route('facilities.create'))->assertOk();
            $this->actingAs($user)->get(route('requisitions.create'))->assertOk();
        }
    }

    public function test_the_sidebar_hides_the_form_links_from_a_student_and_shows_them_to_a_department_head(): void
    {
        $this->actingAs($this->make(Role::REQUESTER))->get(route('notifications.index'))->assertOk()
            ->assertDontSee('Use of facilities')->assertDontSee('Requisition slip');

        $this->actingAs($this->make(Role::DEPARTMENT_HEAD))->get(route('notifications.index'))->assertOk()
            ->assertSee('Use of facilities')->assertSee('Requisition slip');
    }

    public function test_the_head_of_maintenance_may_sign_but_not_file(): void
    {
        $head = $this->make(Role::HEAD);

        $this->actingAs($head)->get(route('facilities.create'))->assertForbidden();
        $this->actingAs($head)->get(route('requisitions.create'))->assertForbidden();
        $this->actingAs($head)->get(route('facilities.index'))->assertOk();
    }
}
