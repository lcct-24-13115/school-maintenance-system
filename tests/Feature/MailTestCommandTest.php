<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_it_sends_the_test_email(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'someone@example.com'])->assertSuccessful();

        Mail::assertSent(TestMail::class, fn (TestMail $m) => $m->hasTo('someone@example.com'));
    }

    public function test_it_rejects_a_bad_address(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'not-an-email'])->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_the_email_uses_the_brand_layout(): void
    {
        $html = (new TestMail('Oct 29, 2026 1:00 PM'))->render();

        $this->assertStringContainsString(config('mail.brand.name'), $html);
        $this->assertStringContainsString('Email is working', $html);
        $this->assertStringContainsString('Oct 29, 2026 1:00 PM', $html);
    }
}
