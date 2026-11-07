<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200)->assertSee('Employee / ID number');
    }

    public function test_password_can_be_changed_directly_with_matching_email_and_id(): void
    {
        $user = User::factory()->create(['employee_no' => 'EMP-001']);

        $this->post('/forgot-password', [
            'email' => $user->email,
            'employee_no' => 'EMP-001',
            'password' => 'NewPassw0rd!2026',
            'password_confirmation' => 'NewPassw0rd!2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewPassw0rd!2026', $user->fresh()->password));
    }

    public function test_wrong_id_number_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['employee_no' => 'EMP-001']);
        $old = $user->password;

        $this->post('/forgot-password', [
            'email' => $user->email,
            'employee_no' => 'WRONG',
            'password' => 'NewPassw0rd!2026',
            'password_confirmation' => 'NewPassw0rd!2026',
        ])->assertSessionHasErrors('email');

        $this->assertSame($old, $user->fresh()->password);
    }
}
