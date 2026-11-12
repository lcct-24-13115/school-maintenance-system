<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "I am a ...": the questions on /register depend on the chosen type (config/registration.php).
class RegistrationTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['school.allow_public_registration' => true]);
        $this->seed(RoleSeeder::class);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Test Person', 'email' => 'test@lcctanauan.edu.ph',
            'password' => 'Sup3r-Secret-Pw!9', 'password_confirmation' => 'Sup3r-Secret-Pw!9',
        ], $extra);
    }

    public function test_form_shows_every_type_tile(): void
    {
        $response = $this->get('/register')->assertOk();

        foreach (config('registration.types') as $key => $type) {
            $response->assertSee('value="'.$key.'"', false)->assertSee($type['label']);
        }
    }

    public function test_student_needs_nothing_extra_and_is_saved_with_its_type(): void
    {
        $this->post('/register', $this->payload(['requester_type' => 'STUDENT', 'affiliation' => 'BSIT, 3rd year']))
            ->assertRedirect('/login');

        $user = User::where('email', 'test@lcctanauan.edu.ph')->firstOrFail();
        $this->assertSame('STUDENT', $user->requester_type);
        $this->assertSame('BSIT, 3rd year', $user->affiliation);
        $this->assertTrue($user->isPending());
    }

    public function test_only_student_and_faculty_tiles_exist(): void
    {
        $this->assertSame(['STUDENT', 'FACULTY/STAFF'], array_keys(config('registration.types')));
    }

    public function test_removed_types_are_refused(): void
    {
        foreach (['ALUMNI', 'PARENT/GUARDIAN', 'EXTERNAL ORGANIZATION', 'OTHER'] as $type) {
            $this->from('/register')
                ->post('/register', $this->payload(['requester_type' => $type]))
                ->assertSessionHasErrors('requester_type');
        }

        $this->assertSame(0, User::count());
    }

    public function test_faculty_must_use_the_school_email(): void
    {
        $this->from('/register')
            ->post('/register', $this->payload(['requester_type' => 'FACULTY/STAFF', 'email' => 'maria@gmail.com']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
    }
    public function test_student_still_needs_the_school_email(): void
    {
        $this->from('/register')
            ->post('/register', $this->payload(['requester_type' => 'STUDENT', 'email' => 'juan@gmail.com']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
    }

    public function test_unknown_type_is_refused(): void
    {
        $this->from('/register')
            ->post('/register', $this->payload(['requester_type' => 'HACKER']))
            ->assertSessionHasErrors('requester_type');

        $this->assertSame(0, User::count());
    }

    public function test_pending_panel_shows_what_the_person_wrote(): void
    {
        $this->post('/register', $this->payload([
            'requester_type' => 'FACULTY/STAFF', 'affiliation' => 'College of Education', 'purpose' => 'Report repairs for room 204.',
        ]));

        // Same set-up AccountApprovalTest uses to open the admin Users page.
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(\Database\Seeders\CategorySeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $admin = User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertSee('Faculty / Staff')->assertSee('College of Education')->assertSee('Report repairs for room 204.');
    }
}
