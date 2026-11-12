<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
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

// The full dashboard for Staff, Head and Maintenance Admin (/maintenance/dashboard).
class MaintenanceDashboardTest extends TestCase
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

    private function make(string $slug): User
    {
        return User::updateOrCreate(['email' => $slug.'-dash@lcctanauan.edu.ph'], [
            'name' => 'Test '.$slug,
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
    }

    private function ticket(array $overrides = []): MaintenanceRequest
    {
        $requester = User::where('email', 'requester@lcctanauan.edu.ph')->firstOrFail();

        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
        ]);
    }

    public function test_staff_head_and_admin_all_get_the_full_dashboard(): void
    {
        foreach ([Role::STAFF, Role::HEAD, Role::MAINTENANCE_ADMIN] as $slug) {
            $this->actingAs($this->make($slug))->get(route('maintenance.dashboard'))->assertOk()
                ->assertSee('Needs attention')->assertSee('Priority queue')->assertSee('Ticket trend')
                ->assertSee('Service levels')->assertSee('Recent activity');
        }
    }

    public function test_staff_workload_is_for_head_and_admin_only(): void
    {
        $this->actingAs($this->make(Role::STAFF))->get(route('maintenance.dashboard'))->assertOk()->assertDontSee('Staff workload');
        $this->actingAs($this->make(Role::HEAD))->get(route('maintenance.dashboard'))->assertOk()->assertSee('Staff workload');
        $this->actingAs($this->admin())->get(route('maintenance.dashboard'))->assertOk()->assertSee('Staff workload');
    }

    public function test_requesters_cannot_open_it(): void
    {
        $requester = User::where('email', 'requester@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($requester)->get(route('maintenance.dashboard'))->assertForbidden();
    }

    public function test_the_needs_attention_tiles_count_the_right_tickets(): void
    {
        $admin = $this->admin();

        $this->ticket(['is_emergency' => true, 'emergency_reason' => 'Flooded room']);                                  // Urgent
        $this->ticket(['due_at' => now()->subDay()]);                                                                    // Overdue
        $this->ticket(['status' => TicketStatus::Approved->value]);                                                      // Unassigned
        $this->ticket(['status' => TicketStatus::Assigned->value, 'assigned_to' => $admin->id, 'assigned_at' => now()]); // Assigned to me

        $this->actingAs($admin)->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('attention', fn ($a) => $a['Urgent']['count'] === 1
                && $a['Overdue']['count'] === 1
                && $a['Unassigned']['count'] === 1
                && $a['Assigned to me']['count'] === 1);
    }

    public function test_the_priority_queue_puts_the_nearest_target_date_first(): void
    {
        $later = $this->ticket(['due_at' => now()->addDays(5)]);
        $sooner = $this->ticket(['due_at' => now()->addDay()]);

        $this->actingAs($this->admin())->get(route('maintenance.dashboard'))->assertOk()
            ->assertSeeInOrder([$sooner->ticket_no, $later->ticket_no]);
    }

    public function test_the_trend_covers_thirty_days(): void
    {
        $this->actingAs($this->admin())->get(route('maintenance.dashboard'))->assertOk()
            ->assertViewHas('trend', fn ($t) => count($t) === 30);
    }
}