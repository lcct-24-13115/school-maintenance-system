<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 2: registration is closed by default and, when opened, only accepts the school's email addresses.
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $email): array
    {
        return ['name' => 'Test User', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'];
    }

    public function test_registration_is_closed_by_default(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->payload('a@lcctanauan.edu.ph'))->assertNotFound();
        $this->assertGuest();
    }

    public function test_registration_screen_can_be_rendered_when_open(): void
    {
        config(['school.allow_public_registration' => true]);

        $this->get('/register')->assertStatus(200);
    }

    public function test_new_users_can_register_with_a_school_email(): void
    {
        config(['school.allow_public_registration' => true]);
        $this->seed(RoleSeeder::class);

        $this->post('/register', $this->payload('juan@lcctanauan.edu.ph'))->assertRedirect('/requester/dashboard');

        $this->assertAuthenticated();
    }

    public function test_other_domains_are_refused(): void
    {
        config(['school.allow_public_registration' => true]);
        $this->seed(RoleSeeder::class);

        $this->from('/register')->post('/register', $this->payload('test@example.com'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_student_number_format_when_selected(): void
    {
        config(['school.allow_public_registration' => true, 'school.registration.email_format' => 'student_number']);
        $this->seed(RoleSeeder::class);

        $this->from('/register')->post('/register', $this->payload('juan@lcctanauan.edu.ph'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/register', $this->payload('24-12803@lcctanauan.edu.ph'))->assertRedirect('/requester/dashboard');
        $this->assertAuthenticated();
    }
}
