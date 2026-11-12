<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Rules\AdvanceNotice;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Multi-department Phase 2: every category belongs to one department. */
class DepartmentCategoryTest extends TestCase
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

    private function department(string $code): Department
    {
        return Department::where('code', $code)->firstOrFail();
    }

    // ---- seeding ---------------------------------------------------------------------------------

    public function test_every_other_department_gets_five_sample_categories_and_a_locked_other(): void
    {
        $codes = ['REG', 'FIN', 'HR', 'ITDC', 'DSA', 'ADMS', 'LIB', 'MKT', 'AEA', 'GUID', 'ACAD'];

        foreach ($codes as $code) {
            $department = $this->department($code);
            $own = Category::where('department_id', $department->id)->where('type', Category::TYPE_SERVICE);

            $this->assertSame(5, (clone $own)->where('name', '!=', Category::OTHER)->count(), "{$code} sample categories");

            $other = (clone $own)->where('name', Category::OTHER)->first();
            $this->assertNotNull($other, "{$code} has an Other category");
            $this->assertTrue($other->is_locked);
        }

        $this->assertTrue(Category::where('name', 'Transcript requests')->first()->department->is($this->department('REG')));
    }

    public function test_the_maintenance_categories_stay_with_the_maintenance_office(): void
    {
        $maintenance = $this->department('MNT');

        $electrical = Category::where('name', 'Electrical')->where('type', 'materials')->firstOrFail();

        $this->assertSame($maintenance->id, $electrical->department_id);
        $this->assertSame(0, Category::whereNull('department_id')->count());
        $this->assertTrue($maintenance->categories->contains($electrical));
    }

    public function test_seeding_twice_does_not_duplicate_categories(): void
    {
        $before = Category::count();

        $this->seed(CategorySeeder::class);

        $this->assertSame($before, Category::count());
    }

    public function test_a_category_without_a_department_goes_to_the_maintenance_office(): void
    {
        $c = Category::create(['name' => 'Painting', 'type' => 'materials']);

        $this->assertSame($this->department('MNT')->id, $c->department_id);
    }

    public function test_the_same_name_can_exist_in_two_departments(): void
    {
        Category::create(['name' => 'General inquiry', 'type' => 'service', 'department_id' => $this->department('REG')->id]);
        Category::create(['name' => 'General inquiry', 'type' => 'service', 'department_id' => $this->department('LIB')->id]);

        $this->assertSame(2, Category::where('name', 'General inquiry')->count());
    }

    // ---- the repair form (Maintenance only) ------------------------------------------------------

    public function test_the_repair_form_lists_only_maintenance_categories(): void
    {
        $this->actingAs($this->user('requester'))->get(route('repairs.create'))
            ->assertOk()
            ->assertSee('>Electrical</option>', false)
            ->assertDontSee('Transcript requests');
    }

    public function test_the_repair_form_refuses_a_category_of_another_department(): void
    {
        $requester = $this->user('requester');
        $base = [
            'department_id' => $requester->department_id,
            'location' => 'Room 1',
            'job_description' => 'Test',
            'reason' => 'Test',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ];

        $this->actingAs($requester)
            ->post(route('repairs.store'), $base + ['category_id' => Category::where('name', 'Transcript requests')->value('id')])
            ->assertSessionHasErrors('category_id');

        $this->actingAs($requester)
            ->post(route('repairs.store'), $base + ['category_id' => Category::where('name', 'Electrical')->value('id')])
            ->assertSessionHasNoErrors();
    }

    // ---- the admin page ---------------------------------------------------------------------------

    public function test_the_admin_page_shows_the_categories_of_the_chosen_department(): void
    {
        $admin = $this->user('maintenance');

        // default = Maintenance Office
        $this->actingAs($admin)->get(route('admin.categories.index'))
            ->assertOk()->assertSee('Electrical')->assertDontSee('Transcript requests');

        $this->actingAs($admin)->get(route('admin.categories.index', ['department' => $this->department('REG')->id]))
            ->assertOk()->assertSee('Transcript requests')->assertDontSee('Electrical');
    }

    public function test_a_category_can_be_added_to_another_department(): void
    {
        $reg = $this->department('REG');

        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.categories.store'), ['department_id' => $reg->id, 'name' => 'ID replacement', 'type' => 'service'])
            ->assertSessionHasNoErrors();

        $new = Category::where('name', 'ID replacement')->firstOrFail();
        $this->assertSame($reg->id, $new->department_id);
        $this->assertSame(Category::TYPE_SERVICE, $new->type);
    }

    public function test_other_departments_only_accept_the_service_type_and_unique_names(): void
    {
        $admin = $this->user('maintenance');
        $reg = $this->department('REG');

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['department_id' => $reg->id, 'name' => 'Pipes', 'type' => 'materials'])
            ->assertSessionHasErrors('type');

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['department_id' => $reg->id, 'name' => 'Transcript requests', 'type' => 'service'])
            ->assertSessionHasErrors('name');

        // the same name in ANOTHER department is fine
        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['department_id' => $this->department('LIB')->id, 'name' => 'Transcript requests', 'type' => 'service'])
            ->assertSessionHasNoErrors();
    }

    public function test_deleting_a_category_moves_its_tickets_to_other_of_the_same_department(): void
    {
        $requester = $this->user('requester');
        $reg = $this->department('REG');
        $category = Category::where('name', 'Transcript requests')->firstOrFail();

        $ticket = MaintenanceRequest::create([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'assigned_department_id' => $reg->id,
            'category_id' => $category->id,
            'maintenance_type' => 'request',
            'location' => 'Registrar',
            'description' => 'Need my transcript',
        ]);

        $this->actingAs($this->user('maintenance'))
            ->delete(route('admin.categories.destroy', $category))
            ->assertSessionHasNoErrors();

        $registrarOther = Category::where('department_id', $reg->id)->where('name', Category::OTHER)->firstOrFail();

        $this->assertSame($registrarOther->id, $ticket->fresh()->category_id);
        $this->assertNull(Category::find($category->id));
    }
}
