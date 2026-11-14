<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Phase 6: role/department dashboard counts, receiving-department queue filters and LIKE wildcard search. */
class DepartmentDashboardAndQueueTest extends TestCase
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

    private function user(string $email): User
    {
        return User::where('email', $email.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function staff(Department $department, string $email): User
    {
        return User::create([
            'name' => 'Staff '.strtoupper($email),
            'email' => $email.'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', Role::STAFF)->value('id'),
            'department_id' => $department->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function head(Department $department, string $email): User
    {
        return User::create([
            'name' => 'Head '.strtoupper($email),
            'email' => $email.'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', Role::HEAD)->value('id'),
            'department_id' => $department->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function ticket(Department $receiving, array $overrides = []): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'assigned_department_id' => $receiving->id,
            'category_id' => Category::orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test dashboard ticket',
        ]);
    }

    public function test_staff_dashboard_counts_personal_queue_unassigned_queue_and_overdue_only_in_its_department(): void
    {
        $finance = Department::where('code', 'FIN')->firstOrFail();
        $registrar = Department::where('code', 'REG')->firstOrFail();
        $staff = $this->staff($finance, 'finance-staff-dashboard');
        $head = $this->head($finance, 'finance-head-dashboard');

        $mine = $this->ticket($finance, ['assigned_to' => $staff->id, 'status' => TicketStatus::InProgress->value]);
        $unassigned = $this->ticket($finance, ['status' => TicketStatus::Approved->value]);
        $overdue = $this->ticket($finance, ['assigned_to' => $staff->id, 'status' => TicketStatus::Assigned->value, 'due_at' => now()->subDay()]);
        // A ticket assigned to this user but received by Registrar must not leak into the department queue counts.
        $outside = $this->ticket($registrar, ['assigned_to' => $staff->id, 'status' => TicketStatus::InProgress->value]);

        $this->actingAs($staff)->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['Assigned to me'] === 2
                && $cards['Unassigned department queue'] === 1
                && $cards['Overdue'] === 1)
            ->assertViewHas('tickets', fn ($rows) => $rows->contains('id', $mine->id) && $rows->contains('id', $overdue->id)
                && ! $rows->contains('id', $outside->id))
            ->assertDontSee($head->name);

        $this->actingAs($head)->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['All department tickets'] === 3
                && $cards['Open department tickets'] === 3
                && $cards['Overdue'] === 1)
            ->assertViewHas('workload', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $staff->id && $row['open'] === 2));
    }

    public function test_head_dashboard_counts_escalated_tickets_only_in_its_own_department(): void
    {
        $finance = Department::where('code', 'FIN')->firstOrFail();
        $registrar = Department::where('code', 'REG')->firstOrFail();
        $head = $this->head($finance, 'finance-head-escalated');
        $local = $this->ticket($finance, ['status' => TicketStatus::Escalated->value]);
        $this->ticket($registrar, ['status' => TicketStatus::Escalated->value]);

        $this->actingAs($head)->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['Escalated'] === 1)
            ->assertViewHas('escalatedTickets', fn ($tickets) => $tickets->count() === 1 && $tickets->first()->is($local));
    }

    public function test_system_admin_dashboard_counts_by_receiving_department_including_zero_departments(): void
    {
        $admin = $this->user('sysadmin');
        $finance = Department::where('code', 'FIN')->firstOrFail();
        $registrar = Department::where('code', 'REG')->firstOrFail();
        $this->ticket($finance, ['status' => TicketStatus::Submitted->value]);
        $this->ticket($finance, ['status' => TicketStatus::Approved->value, 'due_at' => now()->subDay()]);
        $this->ticket($registrar, ['status' => TicketStatus::Escalated->value]);

        $this->actingAs($admin)->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('departments', function ($rows) use ($finance, $registrar) {
                $fin = $rows->firstWhere('id', $finance->id);
                $reg = $rows->firstWhere('id', $registrar->id);
                $zero = $rows->firstWhere('total', 0);

                return $fin['total'] === 2 && $fin['open'] === 2 && $fin['overdue'] === 1
                    && $reg['total'] === 1 && $reg['open'] === 1 && $zero !== null;
            })
            ->assertViewHas('cards', fn ($cards) => $cards['Departments'] === Department::count()
                && $cards['Open tickets'] === 3 && $cards['Overdue'] === 1 && $cards['Escalated'] === 1);
    }

    public function test_ticket_list_filters_by_receiving_department_status_priority_assignee_and_date_range(): void
    {
        $finance = Department::where('code', 'FIN')->firstOrFail();
        $registrar = Department::where('code', 'REG')->firstOrFail();
        $assignee = $this->staff($finance, 'filter-staff');
        $matched = $this->ticket($finance, [
            'status' => TicketStatus::Assigned->value,
            'assigned_to' => $assignee->id,
            'assigned_at' => now(),
            'is_emergency' => true,
            'emergency_reason' => 'Urgent test',
            'submitted_at' => now()->startOfDay(),
            'description' => 'Specific queue keyword',
        ]);
        $this->ticket($finance, ['status' => TicketStatus::Approved->value]);
        $this->ticket($registrar, ['status' => TicketStatus::Assigned->value, 'assigned_to' => $assignee->id, 'assigned_at' => now(), 'is_emergency' => true, 'emergency_reason' => 'Other office']);

        $this->actingAs($this->user('sysadmin'))->get(route('tickets.index', [
            'department_id' => $finance->id,
            'status' => [TicketStatus::Assigned->value],
            'priority' => 'emergency',
            'assigned_to' => $assignee->id,
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'q' => 'Specific queue',
        ]))->assertOk()->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1 && $tickets->first()->is($matched));
    }

    public function test_search_escapes_percent_and_underscore_instead_of_treating_them_as_wildcards(): void
    {
        $finance = Department::where('code', 'FIN')->firstOrFail();
        $literal = $this->ticket($finance, ['description' => 'Cable usage 50%_complete']);
        $this->ticket($finance, ['description' => 'Cable usage 50abccomplete']);

        $this->actingAs($this->user('sysadmin'))->get(route('tickets.index', ['q' => '50%_']))
            ->assertOk()->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1 && $tickets->first()->is($literal));
    }

    public function test_numeric_search_matches_internal_ticket_id_and_ticket_number(): void
    {
        $ticket = $this->ticket(Department::where('code', 'FIN')->firstOrFail());

        $this->actingAs($this->user('sysadmin'))->get(route('tickets.index', ['q' => (string) $ticket->id]))
            ->assertOk()->assertViewHas('tickets', fn ($tickets) => $tickets->contains('id', $ticket->id));
        $this->actingAs($this->user('sysadmin'))->get(route('tickets.index', ['q' => $ticket->ticket_no]))
            ->assertOk()->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1 && $tickets->first()->is($ticket));
    }
}
