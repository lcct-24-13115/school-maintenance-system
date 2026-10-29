<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\AuditLogger;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 5: categories admin (manage_categories, hide / show, reorder, lock, delete -> Other). */
class CategoryAdminTest extends TestCase
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
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function admin(): User
    {
        return $this->user('maintenance');
    }

    private function category(string $name, string $type = 'materials'): Category
    {
        return Category::where('name', $name)->where('type', $type)->firstOrFail();
    }

    private function ticketIn(Category $category, array $extra = []): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return TicketWorkflow::open($requester, $extra + [
            'department_id' => $requester->department_id,
            'category_id' => $category->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Something broken',
        ]);
    }

    private function formData(User $user, array $overrides = []): array
    {
        return $overrides + [
            'department_id' => $user->department_id,
            'category_id' => $this->category('Electrical')->id,
            'location' => 'Grade 5 Room 204',
            'job_description' => 'Broken light',
            'reason' => 'Dark classroom.',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ];
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_only_roles_with_manage_categories_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.categories.index'))->assertOk()->assertSee('Add a category');

        foreach (['requester', 'finance', 'president', 'custodian', 'depthead', 'treasurer'] as $name) {
            $this->actingAs($this->user($name))->get(route('admin.categories.index'))->assertForbidden();
            $this->actingAs($this->user($name))->post(route('admin.categories.store'), ['name' => 'X', 'type' => 'materials'])->assertForbidden();
        }

        $this->assertDatabaseMissing('categories', ['name' => 'X']);
    }

    // ---- create / edit --------------------------------------------------------------------------

    public function test_a_category_can_be_added_and_goes_to_the_end_of_its_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), ['name' => 'Painting', 'type' => 'materials', 'description' => 'Walls and doors'])
            ->assertSessionHasNoErrors();

        $new = $this->category('Painting');
        $this->assertFalse($new->is_hidden);
        $this->assertFalse($new->is_locked);
        $this->assertGreaterThan((int) $this->category('Electrical')->sort_order, $new->sort_order);
        $this->assertSame(1, \App\Models\AuditLog::where('action', AuditLogger::CATEGORY_CHANGED)->count());
    }

    public function test_the_name_must_be_unique_inside_its_type_only(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), ['name' => 'Electrical', 'type' => 'materials'])
            ->assertSessionHasErrors('name');

        // same name in another type is fine
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), ['name' => 'Electrical', 'type' => 'supplies'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_category_can_be_edited(): void
    {
        $c = $this->category('Carpentry / Civil Works');

        $this->actingAs($this->admin())
            ->put(route('admin.categories.update', $c), ['name' => 'Carpentry', 'type' => 'materials', 'description' => 'Wood work'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Carpentry', $c->fresh()->name);
        $this->assertSame('Wood work', $c->fresh()->description);
    }

    public function test_a_locked_category_keeps_its_name_and_type(): void
    {
        $c = $this->category('Electrical');
        $c->update(['is_locked' => true]);

        $this->actingAs($this->admin())
            ->put(route('admin.categories.update', $c), ['name' => 'Renamed', 'type' => 'equipment', 'is_locked' => '1'])
            ->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('Electrical', $c->name);
        $this->assertSame('materials', $c->type);
        $this->assertTrue($c->is_locked);
    }

    public function test_the_type_cannot_change_once_tickets_use_the_category(): void
    {
        $c = $this->category('Electrical');
        $this->ticketIn($c);

        $this->actingAs($this->admin())
            ->put(route('admin.categories.update', $c), ['name' => 'Electrical', 'type' => 'equipment'])
            ->assertSessionHasErrors('type');

        $this->assertSame('materials', $c->fresh()->type);
    }

    // ---- hide / show ----------------------------------------------------------------------------

    public function test_a_hidden_category_is_gone_from_the_repair_form_and_cannot_be_posted(): void
    {
        $requester = $this->user('requester');
        $c = $this->category('Electrical');

        $this->actingAs($requester)->get(route('repairs.create'))->assertOk()->assertSee('>Electrical</option>', false);

        $this->actingAs($this->admin())->patch(route('admin.categories.toggle', $c))->assertSessionHasNoErrors();
        $this->assertTrue($c->fresh()->is_hidden);

        $this->actingAs($requester)->get(route('repairs.create'))->assertOk()->assertDontSee('>Electrical</option>', false);

        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester, ['category_id' => $c->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('requests', 0);

        // show again -> it is back, and filing works
        $this->actingAs($this->admin())->patch(route('admin.categories.toggle', $c));
        $this->assertFalse($c->fresh()->is_hidden);

        $this->actingAs($requester)->get(route('repairs.create'))->assertSee('>Electrical</option>', false);
        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester, ['category_id' => $c->id]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('requests', 1);
    }

    public function test_an_old_ticket_keeps_showing_its_hidden_category(): void
    {
        $c = $this->category('Electrical');
        $ticket = $this->ticketIn($c);
        $c->update(['is_hidden' => true]);

        $this->actingAs($this->admin())->get(route('tickets.show', $ticket))->assertOk()->assertSee('Electrical');
    }

    // ---- reorder --------------------------------------------------------------------------------

    public function test_move_up_and_down_swaps_neighbours_inside_the_type(): void
    {
        $order = fn () => Category::where('type', 'materials')->ordered()->orderBy('id')->pluck('name')->all();

        $before = $order();
        $this->assertSame('Plumbing (faucets, pipes)', $before[0]);
        $this->assertSame('Electrical', $before[1]);

        $this->actingAs($this->admin())->post(route('admin.categories.move', $this->category('Electrical')), ['direction' => 'up']);
        $after = $order();
        $this->assertSame('Electrical', $after[0]);
        $this->assertSame('Plumbing (faucets, pipes)', $after[1]);

        $this->actingAs($this->admin())->post(route('admin.categories.move', $this->category('Electrical')), ['direction' => 'down']);
        $this->assertSame($before, $order());

        // the first one cannot go further up: nothing changes
        $this->actingAs($this->admin())->post(route('admin.categories.move', $this->category('Plumbing (faucets, pipes)')), ['direction' => 'up']);
        $this->assertSame($before, $order());
    }

    public function test_the_repair_form_lists_categories_in_sort_order(): void
    {
        $this->category('Electrical')->update(['sort_order' => 0]);

        $html = $this->actingAs($this->user('requester'))->get(route('repairs.create'))->getContent();

        $this->assertLessThan(strpos($html, 'Plumbing (faucets, pipes)'), strpos($html, '>Electrical'));
    }

    // ---- delete ---------------------------------------------------------------------------------

    public function test_deleting_moves_tickets_to_other_of_the_same_type(): void
    {
        $c = $this->category('Electrical');
        $plain = $this->ticketIn($c);
        $typed = $this->ticketIn($c, ['request_type' => 'materials']);

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $c))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('categories', ['id' => $c->id]);

        $other = $this->category('Other');
        $this->assertTrue($other->is_locked);
        $this->assertSame($other->id, $plain->fresh()->category_id);
        $this->assertSame($other->id, $typed->fresh()->category_id);
        $this->assertSame('materials', $typed->fresh()->request_type);
    }

    public function test_other_is_created_on_demand_when_it_does_not_exist(): void
    {
        Category::where('name', 'Other')->delete();
        $c = $this->category('Electrical');
        $ticket = $this->ticketIn($c);

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $c))->assertSessionHasNoErrors();

        $other = Category::where('name', 'Other')->where('type', 'materials')->firstOrFail();
        $this->assertSame($other->id, $ticket->fresh()->category_id);
    }

    public function test_a_locked_category_cannot_be_deleted(): void
    {
        $other = $this->category('Other');

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $other))->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $other->id]);
    }
}
