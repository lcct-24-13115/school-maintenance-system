<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Ticket list: Priority filter, "Assigned to: me / none" and sorting by column (/tickets).
class TicketListSortTest extends TestCase
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

    private function list(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->get(route('tickets.index', $query))->assertOk();
    }

    public function test_priority_filter_splits_emergency_and_routine(): void
    {
        $this->ticket(['is_emergency' => true, 'emergency_reason' => 'Flooded room']);
        $this->ticket();
        $this->ticket();

        $this->list(['priority' => 'emergency'])->assertViewHas('tickets', fn ($p) => $p->total() === 1);
        $this->list(['priority' => 'routine'])->assertViewHas('tickets', fn ($p) => $p->total() === 2);
        $this->list()->assertViewHas('tickets', fn ($p) => $p->total() === 3);
    }

    public function test_the_old_emergency_link_still_works(): void
    {
        $this->ticket(['is_emergency' => true, 'emergency_reason' => 'Flooded room']);
        $this->ticket();

        $this->list(['emergency' => 1])->assertViewHas('tickets', fn ($p) => $p->total() === 1);
    }

    public function test_a_bad_priority_is_refused(): void
    {
        $this->actingAs($this->admin())->get(route('tickets.index', ['priority' => 'nonsense']))->assertSessionHasErrors('priority');
    }

    public function test_assigned_to_me_and_none(): void
    {
        $admin = $this->admin();
        $this->ticket(['status' => TicketStatus::Assigned->value, 'assigned_to' => $admin->id, 'assigned_at' => now()]);
        $this->ticket();
        $this->ticket();

        $this->list(['assigned_to' => 'me'])->assertViewHas('tickets', fn ($p) => $p->total() === 1);
        $this->list(['assigned_to' => 'unassigned'])->assertViewHas('tickets', fn ($p) => $p->total() === 2);
    }

    public function test_sorting_by_ticket_number_goes_both_ways(): void
    {
        $first = $this->ticket();
        $second = $this->ticket();

        $this->list(['sort' => 'ticket', 'dir' => 'asc'])->assertSeeInOrder([$first->ticket_no, $second->ticket_no]);
        $this->list(['sort' => 'ticket', 'dir' => 'desc'])->assertSeeInOrder([$second->ticket_no, $first->ticket_no]);
    }

    public function test_priority_sort_puts_emergencies_first_when_ascending(): void
    {
        $routine = $this->ticket();
        $urgent = $this->ticket(['is_emergency' => true, 'emergency_reason' => 'Flooded room']);

        $this->list(['sort' => 'priority', 'dir' => 'asc'])->assertSeeInOrder([$urgent->ticket_no, $routine->ticket_no]);
        $this->list(['sort' => 'priority', 'dir' => 'desc'])->assertSeeInOrder([$routine->ticket_no, $urgent->ticket_no]);
    }

    public function test_every_sort_column_runs_without_an_error(): void
    {
        $this->ticket();

        foreach (['ticket', 'status', 'location', 'category', 'requester', 'assigned', 'submitted', 'completed', 'priority', 'due'] as $key) {
            foreach (['asc', 'desc'] as $dir) {
                $this->list(['sort' => $key, 'dir' => $dir]);
            }
        }

        foreach (['ticket', 'status', 'location', 'category', 'requester', 'assigned', 'submitted', 'completed'] as $key) {
            $this->actingAs($this->admin())->get(route('tickets.history', ['sort' => $key, 'dir' => 'desc']))->assertOk();
        }
    }

    public function test_an_unknown_sort_falls_back_to_the_default_order(): void
    {
        $this->ticket();

        $this->list(['sort' => 'password; drop table', 'dir' => 'sideways'])->assertViewHas('sort', null);
    }

    public function test_the_sort_stays_on_the_next_page(): void
    {
        foreach (range(1, 16) as $i) {
            $this->ticket();
        }

        $this->list(['sort' => 'ticket', 'dir' => 'desc'])->assertSee('sort=ticket', false)->assertSee('dir=desc', false);
    }
}