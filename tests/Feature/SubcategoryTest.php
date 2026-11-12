<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\Subcategory;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Subcategories: optional sub-types of a category (admin page, repair form, ticket pages). */
class SubcategoryTest extends TestCase
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

    private function sub(string $category, string $name, string $type = 'materials'): Subcategory
    {
        return $this->category($category, $type)->subcategories()->where('name', $name)->firstOrFail();
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

    // ---- admin ----------------------------------------------------------------------------------

    public function test_the_seeder_creates_starter_subcategories_and_can_run_twice(): void
    {
        $this->assertSame('Electrical', $this->sub('Electrical', 'Lights')->category->name);
        $before = Subcategory::count();

        $this->seed(CategorySeeder::class);

        $this->assertSame($before, Subcategory::count());
    }

    public function test_only_roles_with_manage_categories_can_change_subcategories(): void
    {
        $category = $this->category('Electrical');
        $sub = $this->sub('Electrical', 'Lights');

        foreach (['requester', 'finance', 'president', 'custodian', 'depthead', 'treasurer'] as $name) {
            $u = $this->user($name);
            $this->actingAs($u)->post(route('admin.categories.subcategories.store', $category), ['name' => 'X'])->assertForbidden();
            $this->actingAs($u)->put(route('admin.categories.subcategories.update', $sub), ['name' => 'X'])->assertForbidden();
            $this->actingAs($u)->patch(route('admin.categories.subcategories.toggle', $sub))->assertForbidden();
            $this->actingAs($u)->delete(route('admin.categories.subcategories.destroy', $sub))->assertForbidden();
        }

        $this->assertDatabaseMissing('subcategories', ['name' => 'X']);
        $this->assertSame('Lights', $sub->fresh()->name);
    }

    public function test_the_categories_page_lists_subcategories(): void
    {
        $this->actingAs($this->admin())->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('Subcategories')
            ->assertSee('Leaking faucet');
    }

    public function test_a_subcategory_can_be_added_renamed_hidden_and_deleted(): void
    {
        $admin = $this->admin();
        $category = $this->category('Electrical');

        $this->actingAs($admin)
            ->post(route('admin.categories.subcategories.store', $category), ['name' => 'Generator'])
            ->assertSessionHasNoErrors();
        $sub = $this->sub('Electrical', 'Generator');
        $this->assertFalse($sub->is_hidden);

        $this->actingAs($admin)
            ->put(route('admin.categories.subcategories.update', $sub), ['name' => 'Generator set'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Generator set', $sub->fresh()->name);

        $this->actingAs($admin)->patch(route('admin.categories.subcategories.toggle', $sub))->assertSessionHasNoErrors();
        $this->assertTrue($sub->fresh()->is_hidden);

        $this->actingAs($admin)->delete(route('admin.categories.subcategories.destroy', $sub))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('subcategories', ['id' => $sub->id]);

        $this->assertSame(4, \App\Models\AuditLog::where('action', AuditLogger::CATEGORY_CHANGED)->count());
    }

    public function test_the_name_must_be_unique_inside_its_category_only(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.subcategories.store', $this->category('Electrical')), ['name' => 'Lights'])
            ->assertSessionHasErrors('name');

        // the same name under another category is fine
        $this->actingAs($this->admin())
            ->post(route('admin.categories.subcategories.store', $this->category('Plumbing (faucets, pipes)')), ['name' => 'Lights'])
            ->assertSessionHasNoErrors();
    }

    // ---- repair form ----------------------------------------------------------------------------

    public function test_the_form_offers_visible_subcategories_only(): void
    {
        $this->sub('Electrical', 'Circuit breaker')->update(['is_hidden' => true]);

        $this->actingAs($this->user('requester'))->get(route('repairs.create'))
            ->assertOk()
            ->assertSee('Leaking faucet')
            ->assertDontSee('Circuit breaker');
    }

    public function test_a_ticket_can_be_filed_with_a_subcategory_and_shows_it(): void
    {
        $requester = $this->user('requester');
        $sub = $this->sub('Electrical', 'Lights');

        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester, ['subcategory_id' => $sub->id]))
            ->assertSessionHasNoErrors();

        $ticket = MaintenanceRequest::latest('id')->firstOrFail();
        $this->assertSame($sub->id, $ticket->subcategory_id);

        $this->actingAs($requester)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Lights');
        $this->actingAs($requester)->get(route('tickets.index'))->assertOk()->assertSee('Lights');
    }

    public function test_the_subcategory_is_optional(): void
    {
        $requester = $this->user('requester');

        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester))
            ->assertSessionHasNoErrors();

        $this->assertNull(MaintenanceRequest::latest('id')->firstOrFail()->subcategory_id);
    }

    public function test_a_subcategory_of_another_category_is_refused(): void
    {
        $requester = $this->user('requester');
        $foreign = $this->sub('Plumbing (faucets, pipes)', 'Leaking faucet');

        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester, ['subcategory_id' => $foreign->id]))
            ->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_a_hidden_subcategory_is_refused_on_new_tickets(): void
    {
        $requester = $this->user('requester');
        $sub = $this->sub('Electrical', 'Lights');
        $sub->update(['is_hidden' => true]);

        $this->actingAs($requester)
            ->post(route('repairs.store'), $this->formData($requester, ['subcategory_id' => $sub->id]))
            ->assertSessionHasErrors('subcategory_id');
    }

    public function test_the_model_refuses_a_subcategory_of_a_different_category(): void
    {
        $requester = $this->user('requester');
        $foreign = $this->sub('Plumbing (faucets, pipes)', 'Leaking faucet');

        $this->expectException(\DomainException::class);

        \App\Services\TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => $this->category('Electrical')->id,
            'subcategory_id' => $foreign->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Something broken',
        ]);
    }

    // ---- deleting ---------------------------------------------------------------------------------

    public function test_deleting_a_subcategory_keeps_the_ticket_and_its_category(): void
    {
        $requester = $this->user('requester');
        $sub = $this->sub('Electrical', 'Lights');
        $ticket = \App\Services\TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => $sub->category_id,
            'subcategory_id' => $sub->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Something broken',
        ]);

        $this->actingAs($this->admin())->delete(route('admin.categories.subcategories.destroy', $sub))->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertNull($ticket->subcategory_id);
        $this->assertSame($sub->category_id, $ticket->category_id);
    }

    public function test_deleting_a_category_removes_its_subcategories_and_moves_tickets_to_other(): void
    {
        $requester = $this->user('requester');
        $sub = $this->sub('Electrical', 'Lights');
        $category = $sub->category;
        $ticket = \App\Services\TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => $category->id,
            'subcategory_id' => $sub->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Something broken',
        ]);

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $category))->assertSessionHasNoErrors();

        $this->assertSame(0, Subcategory::where('category_id', $category->id)->count());

        $ticket->refresh();
        $this->assertNull($ticket->subcategory_id);
        $this->assertSame('Other', $ticket->category->name);
    }
}
