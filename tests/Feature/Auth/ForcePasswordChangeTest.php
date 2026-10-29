<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Rules\SchoolEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function tempUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    public function test_a_user_with_a_temporary_password_is_sent_to_the_change_page(): void
    {
        $user = $this->tempUser();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.force'));
        $this->actingAs($user)->get('/tickets')->assertRedirect(route('password.force'));
        $this->actingAs($user)->get('/notifications/poll', ['Accept' => 'application/json'])->assertStatus(423);
    }

    public function test_the_change_page_and_logout_still_work(): void
    {
        $user = $this->tempUser();

        $this->actingAs($user)->get(route('password.force'))->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_choosing_a_new_password_clears_the_flag(): void
    {
        $user = $this->tempUser();

        $this->actingAs($user)->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'a-brand-new-pass',
            'password_confirmation' => 'a-brand-new-pass',
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('a-brand-new-pass', $user->password));
        $this->actingAs($user)->get('/dashboard')->assertRedirect();   // reaches the app (role dashboard), not the change page
        $this->assertNotSame(route('password.force'), $this->actingAs($user)->get('/dashboard')->headers->get('Location'));
    }

    public function test_the_new_password_must_differ_from_the_temporary_one(): void
    {
        $user = $this->tempUser();

        $this->actingAs($user)->from(route('password.force'))->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue((bool) $user->refresh()->must_change_password);
    }

    public function test_a_wrong_temporary_password_is_refused(): void
    {
        $user = $this->tempUser();

        $this->actingAs($user)->from(route('password.force'))->put(route('password.force.update'), [
            'current_password' => 'nope',
            'password' => 'a-brand-new-pass',
            'password_confirmation' => 'a-brand-new-pass',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_normal_users_are_not_affected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect();
        $this->assertNotSame(route('password.force'), $this->actingAs($user)->get('/dashboard')->headers->get('Location'));
        $this->actingAs($user)->get(route('password.force'))->assertRedirect();   // nothing to change: goes to its dashboard
    }

    public function test_school_email_rule(): void
    {
        config(['school.registration.email_domain' => 'lcctanauan.edu.ph', 'school.registration.email_format' => 'domain']);
        $ok = fn (string $e) => Validator::make(['email' => $e], ['email' => [new SchoolEmail]])->passes();

        $this->assertTrue($ok('juan@lcctanauan.edu.ph'));
        $this->assertTrue($ok('JUAN@LCCTANAUAN.EDU.PH'));
        $this->assertFalse($ok('juan@gmail.com'));
        $this->assertFalse($ok('juan@evil.com@lcctanauan.edu.ph'));
        $this->assertFalse($ok('juan@sub.lcctanauan.edu.ph'));

        config(['school.registration.email_format' => 'student_number']);
        $this->assertTrue($ok('24-12803@lcctanauan.edu.ph'));
        $this->assertTrue($ok('2412803@lcctanauan.edu.ph'));
        $this->assertFalse($ok('juan@lcctanauan.edu.ph'));
        $this->assertFalse($ok('24--12803@lcctanauan.edu.ph'));
        $this->assertFalse($ok('-24@lcctanauan.edu.ph'));

        config(['school.registration.email_domain' => '']);
        $this->assertTrue($ok('anyone@anywhere.com'));
    }
}
