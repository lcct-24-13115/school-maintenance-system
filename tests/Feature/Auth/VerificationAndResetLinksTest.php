<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerificationAndResetLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_lifetimes_match_lcct_ticketing(): void
    {
        $this->assertSame(1440, config('auth.verification.expire'));   // 24 hours
        $this->assertSame(60, config('auth.passwords.users.expire'));  // 60 minutes
    }

    public function test_the_emailed_verification_link_lasts_24_hours(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $seconds = (int) $q['expires'] - now()->getTimestamp();

            return $seconds > 23 * 3600 && $seconds <= 24 * 3600;
        });
    }

    public function test_an_expired_verification_link_shows_a_friendly_page(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->travel(10)->minutes();

        $this->actingAs($user)->get($url)->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }

    public function test_the_resend_button_sends_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_is_not_required_by_default(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect();
        $this->assertNotSame(route('verification.notice'), $this->actingAs($user)->get('/dashboard')->headers->get('Location'));
    }

    public function test_when_required_an_unverified_user_only_sees_the_verify_page(): void
    {
        config(['school.registration.require_verification' => true]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/tickets')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect('/');
    }

    public function test_when_required_a_verified_user_is_not_blocked(): void
    {
        config(['school.registration.require_verification' => true]);
        $user = User::factory()->create();

        $this->assertNotSame(route('verification.notice'), $this->actingAs($user)->get('/dashboard')->headers->get('Location'));
    }

    public function test_a_reset_token_stops_working_after_60_minutes(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $status = Password::reset(
            ['email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1', 'token' => $token],
            fn () => null,
        );

        $this->assertSame(Password::INVALID_TOKEN, $status);
    }

    public function test_a_fresh_reset_token_works(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel(30)->minutes();

        $status = Password::reset(
            ['email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1', 'token' => $token],
            fn () => null,
        );

        $this->assertSame(Password::PASSWORD_RESET, $status);
    }
}
