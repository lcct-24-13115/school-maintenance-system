<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    /** Assert the login error for "email" contains every given text. */
    private function assertEmailError($response, string ...$texts): void
    {
        $response->assertSessionHasErrors('email');
        $message = (string) session('errors')->first('email');
        foreach ($texts as $t) {
            $this->assertStringContainsString($t, $message);
        }
    }

    private function bad(User $u, string $pw = 'wrong')
    {
        return $this->from('/login')->post('/login', ['email' => $u->email, 'password' => $pw]);
    }

    public function test_wrong_passwords_show_how_many_attempts_are_left(): void
    {
        $u = User::factory()->create();

        $this->assertEmailError($this->bad($u), '4 attempts left');
        $this->assertEmailError($this->bad($u), '3 attempts left');
        $this->assertGuest();
    }

    public function test_the_fifth_wrong_password_locks_sign_in_even_for_the_right_password(): void
    {
        $u = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->bad($u);
        }

        $this->assertEmailError($this->bad($u, 'password'), 'locked', '15 minutes');
        $this->assertGuest();
    }

    public function test_the_lock_ends_after_the_lock_minutes(): void
    {
        $u = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->bad($u);
        }

        $this->travel(16)->minutes();

        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
    }

    public function test_the_lock_is_written_to_the_audit_log_once(): void
    {
        $u = User::factory()->create();
        foreach (range(1, 7) as $i) {
            $this->bad($u);
        }

        $this->assertSame(1, AuditLog::where('action', 'account_locked')->count());
    }

    public function test_a_right_password_before_the_limit_resets_the_count(): void
    {
        $u = User::factory()->create();
        foreach (range(1, 4) as $i) {
            $this->bad($u);
        }
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect();
        auth()->logout();

        $this->assertEmailError($this->bad($u), '4 attempts left');
    }

    public function test_limits_come_from_config(): void
    {
        config(['school.login.max_attempts' => 2, 'school.login.lock_minutes' => 3]);
        $u = User::factory()->create();

        $this->bad($u);
        $this->assertEmailError($this->bad($u), '3 minutes');
        $this->bad($u, 'password')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_another_account_is_not_locked(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->bad($a);
        }

        $this->post('/login', ['email' => $b->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($b);
    }
}
