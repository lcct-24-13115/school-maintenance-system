<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Personnel only (config school.personnel_only): the system is not open to Student accounts.
 * phpunit.xml sets PERSONNEL_ONLY=false so the older tests (which use a Requester as a plain ticket filer) keep their
 * behaviour; every test here turns the rule on again.
 */
class PersonnelOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['school.personnel_only' => true]);
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function account(string $slug, array $overrides = []): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
        ] + $overrides);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'requester_type' => 'FACULTY/STAFF',
            'name' => 'Maria Santos',
            'email' => 'maria.santos@lcctanauan.edu.ph',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }

    // ---- sign in --------------------------------------------------------------------------------

    public function test_a_student_cannot_sign_in_even_with_the_right_password(): void
    {
        $student = $this->account(Role::REQUESTER);

        $this->from('/login')->post('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('personnel only', (string) session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_a_wrong_password_for_a_student_gives_the_normal_message(): void
    {
        $student = $this->account(Role::REQUESTER);

        $this->from('/login')->post('/login', ['email' => $student->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertStringNotContainsString('personnel only', (string) session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_a_teacher_can_still_sign_in(): void
    {
        $teacher = $this->account(Role::TEACHER);

        $this->post('/login', ['email' => $teacher->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_the_maintenance_admin_can_still_sign_in(): void
    {
        $this->post('/login', ['email' => 'maintenance@lcctanauan.edu.ph', 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_a_student_who_is_already_signed_in_is_signed_out(): void
    {
        $student = $this->account(Role::REQUESTER);

        $this->actingAs($student)->get('/requester/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_rule_can_be_switched_off(): void
    {
        config(['school.personnel_only' => false]);
        $student = $this->account(Role::REQUESTER);

        $this->post('/login', ['email' => $student->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }

    // ---- registration ---------------------------------------------------------------------------

    public function test_the_registration_form_offers_personnel_only(): void
    {
        config(['school.allow_public_registration' => true]);

        $this->get('/register')->assertOk()
            ->assertSee('Personnel registration')
            ->assertDontSee('Parent / Guardian');
    }

    public function test_a_personnel_registration_creates_a_teacher_account(): void
    {
        config(['school.allow_public_registration' => true, 'school.registration.require_approval' => false]);

        $this->post('/register', $this->registrationPayload())->assertRedirect();

        $this->assertSame(Role::TEACHER, User::where('email', 'maria.santos@lcctanauan.edu.ph')->firstOrFail()->role->slug);
    }

    public function test_the_student_registration_type_is_refused(): void
    {
        config(['school.allow_public_registration' => true]);
        $before = User::count();

        $this->from('/register')->post('/register', $this->registrationPayload(['requester_type' => 'STUDENT']))
            ->assertSessionHasErrors('requester_type');

        $this->assertSame($before, User::count());
    }

    public function test_a_student_number_email_is_refused(): void
    {
        config(['school.allow_public_registration' => true]);
        $before = User::count();

        $this->from('/register')->post('/register', $this->registrationPayload(['email' => '24-12803@lcctanauan.edu.ph']))
            ->assertSessionHasErrors('email');

        $this->assertSame($before, User::count());
    }

    // ---- admin: users page ----------------------------------------------------------------------

    public function test_the_users_page_still_opens(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    }

    public function test_an_admin_cannot_create_a_student_account(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
        $before = User::count();

        $this->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.store'), [
            'name' => 'Pedro Cruz',
            'email' => 'pedro.cruz@lcctanauan.edu.ph',
            'role_id' => Role::where('slug', Role::REQUESTER)->value('id'),
            'department_id' => Department::where('code', 'HS')->value('id'),
        ])->assertSessionHasErrors('role_id');

        $this->assertSame($before, User::count());
    }

    public function test_an_admin_can_create_a_teacher_account(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Pedro Cruz',
            'email' => 'pedro.cruz@lcctanauan.edu.ph',
            'role_id' => Role::where('slug', Role::TEACHER)->value('id'),
            'department_id' => Department::where('code', 'HS')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'pedro.cruz@lcctanauan.edu.ph']);
    }

    public function test_an_admin_cannot_turn_an_account_into_a_student(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
        $teacher = $this->account(Role::TEACHER);

        $this->actingAs($admin)->from(route('admin.users.index'))->put(route('admin.users.update', $teacher), [
            'role_id' => Role::where('slug', Role::REQUESTER)->value('id'),
        ])->assertSessionHasErrors('role_id');

        $this->assertSame(Role::TEACHER, $teacher->fresh()->role->slug);
    }

    public function test_an_existing_student_account_can_still_be_edited_without_changing_its_role(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
        $student = $this->account(Role::REQUESTER);

        $this->actingAs($admin)->put(route('admin.users.update', $student), [
            'role_id' => $student->role_id,
            'department_id' => Department::where('code', 'HS')->value('id'),
        ])->assertSessionHasNoErrors();
    }

    // ---- waiting registrations ------------------------------------------------------------------

    public function test_a_waiting_student_or_parent_registration_cannot_be_approved(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();

        foreach (['STUDENT', 'PARENT/GUARDIAN'] as $type) {
            $person = $this->account(Role::REQUESTER, ['approval_status' => User::STATUS_PENDING, 'requester_type' => $type]);

            $this->actingAs($admin)->post(route('admin.users.approve', $person))->assertSessionHas('error');

            $this->assertSame(User::STATUS_PENDING, $person->fresh()->approval_status);
        }
    }

    public function test_a_waiting_faculty_registration_is_approved_as_a_teacher(): void
    {
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
        $person = $this->account(Role::REQUESTER, ['approval_status' => User::STATUS_PENDING, 'requester_type' => 'FACULTY/STAFF']);

        $this->actingAs($admin)->post(route('admin.users.approve', $person));

        $person = $person->fresh();
        $this->assertSame(User::STATUS_APPROVED, $person->approval_status);
        $this->assertSame(Role::TEACHER, $person->role->slug);
    }

    // ---- student-only console commands ----------------------------------------------------------

    public function test_the_student_commands_refuse_to_run(): void
    {
        $this->artisan('students:import', ['files' => ['students.csv']])->assertFailed();
        $this->artisan('students:reset-passwords', ['--force' => true])->assertFailed();
    }
}
