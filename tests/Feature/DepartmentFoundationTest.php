<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Multi-department Phase 1: departments can receive tickets; tickets know which department receives them. */
class DepartmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function newTicket(array $overrides = []): MaintenanceRequest
    {
        $requester = User::where('email', 'requester@lcctanauan.edu.ph')->firstOrFail();

        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
        ]);
    }

    public function test_the_seeder_creates_twelve_active_service_departments_including_maintenance(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertSame(12, Department::count());
        $this->assertSame(12, Department::active()->count());
        $this->assertSame(12, Department::service()->count());
        $this->assertSame('Maintenance Office', Department::where('code', 'MNT')->value('name'));
    }

    public function test_seeding_twice_does_not_duplicate_departments(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(DepartmentSeeder::class);

        $this->assertSame(12, Department::count());
    }

    public function test_maintenance_id_helper(): void
    {
        $this->assertNull(Department::maintenanceId());

        $this->seed(DepartmentSeeder::class);

        $this->assertSame((int) Department::where('code', 'MNT')->value('id'), Department::maintenanceId());
    }

    public function test_scopes_hide_inactive_and_non_service_departments(): void
    {
        $this->seed(DepartmentSeeder::class);
        Department::where('code', 'LIB')->update(['is_active' => false]);
        Department::where('code', 'MKT')->update(['is_service_department' => false]);

        $this->assertSame(11, Department::active()->count());
        $this->assertSame(11, Department::service()->count());
        $this->assertFalse(Department::active()->where('code', 'LIB')->exists());
        $this->assertFalse(Department::service()->where('code', 'MKT')->exists());
    }

    public function test_a_department_has_a_head(): void
    {
        $this->seedBase();
        $head = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
        $department = Department::where('code', 'MNT')->firstOrFail();

        $this->assertNull($department->head);

        $department->update(['head_user_id' => $head->id]);

        $this->assertTrue($department->fresh()->head->is($head));
    }

    public function test_a_new_ticket_is_received_by_the_maintenance_office_by_default(): void
    {
        $this->seedBase();
        $maintenance = Department::where('code', 'MNT')->firstOrFail();

        $ticket = $this->newTicket();

        $this->assertSame($maintenance->id, $ticket->assigned_department_id);
        $this->assertTrue($ticket->assignedDepartment->is($maintenance));
        $this->assertTrue($maintenance->receivedTickets->contains($ticket));
        // The requester's department is a different thing and stays untouched.
        $this->assertNotSame($ticket->assigned_department_id, $ticket->department_id);
    }

    public function test_an_explicit_receiving_department_is_kept(): void
    {
        $this->seedBase();
        $library = Department::where('code', 'LIB')->firstOrFail();

        $ticket = $this->newTicket(['assigned_department_id' => $library->id]);

        $this->assertSame($library->id, $ticket->assigned_department_id);
        $this->assertTrue($library->receivedTickets->contains($ticket));
    }

    public function test_requests_filed_by_a_department_are_still_reachable(): void
    {
        $this->seedBase();
        $ticket = $this->newTicket();

        $this->assertTrue($ticket->department->requests->contains($ticket));
    }
}
