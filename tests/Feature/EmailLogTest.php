<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Mail\TicketNotificationMail;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EmailLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * E-mail log (Admin): every e-mail is recorded with its real result, the page is for the Maintenance Admin only,
 * a failed TICKET e-mail can be sent again, and private e-mails (password reset) never keep their body.
 *
 * phpunit.xml uses MAIL_MAILER=array and QUEUE_CONNECTION=sync, so the mailer's real events fire.
 */
class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        EmailLogger::$current = null;   // a static: do not let one test's e-mail leak into the next
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    /** The view data TicketNotificationMail needs (see TicketMailer::payload). */
    private function ticketData(string $ticketNo = 'MT-2026-00001'): array
    {
        return [
            'name' => 'Test Requester',
            'subject' => "[{$ticketNo}] Ticket approved",
            'headline' => 'Ticket approved',
            'intro' => 'Someone approved the ticket.',
            'ticket_no' => $ticketNo,
            'status' => 'Approved',
            'details' => ['Location' => 'Room 101'],
            'note' => null,
            'url' => 'http://localhost/tickets/1',
            'confidential' => false,
        ];
    }

    private function rawEmail(string $html = '<p>hello</p>'): Email
    {
        return (new Email)->from('noreply@lcctanauan.edu.ph')->to('requester@lcctanauan.edu.ph')->subject('Raw subject')->html($html);
    }

    private function failedTicketLog(array $override = []): EmailLog
    {
        return EmailLog::create($override + [
            'status' => EmailLog::FAILED,
            'type' => 'approved',
            'to_email' => 'requester@lcctanauan.edu.ph',
            'to_name' => 'Test Requester',
            'subject' => '[MT-2026-00001] Ticket approved',
            'ticket_no' => 'MT-2026-00001',
            'mailer' => 'smtp',
            'error' => 'Connection refused',
            'body_html' => '<html><body><p>Ticket approved</p></body></html>',
        ]);
    }

    // ---- recording ------------------------------------------------------------------------------

    public function test_a_sent_email_is_recorded_as_delivered(): void
    {
        Mail::to('someone@example.com')->send(new TestMail('Nov 12, 2026 1:00 PM'));

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::DELIVERED, $log->status);
        $this->assertSame('someone@example.com', $log->to_email);
        $this->assertSame('test', $log->type);
        $this->assertNotNull($log->delivered_at);
        $this->assertNull($log->body_html);   // only ticket e-mails keep their body
        $this->assertSame(1, EmailLog::count());
    }

    public function test_a_ticket_email_keeps_its_body_and_ticket_number(): void
    {
        Mail::to($this->user('requester'))->send(new TicketNotificationMail('approved', $this->ticketData()));

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::DELIVERED, $log->status);
        $this->assertSame('approved', $log->type);
        $this->assertSame('MT-2026-00001', $log->ticket_no);
        $this->assertSame($this->user('requester')->id, $log->user_id);
        $this->assertStringContainsString('Ticket approved', (string) $log->body_html);
    }

    public function test_a_password_reset_email_is_logged_without_its_body(): void
    {
        $email = $this->rawEmail('<a href="http://localhost/reset-password/SECRET-TOKEN-123">Reset</a>');

        event(new MessageSending($email, ['__laravel_notification' => 'Illuminate\\Auth\\Notifications\\ResetPassword']));

        $log = EmailLog::firstOrFail();
        $this->assertSame('reset_password', $log->type);
        $this->assertNull($log->body_html);
        $this->assertStringNotContainsString('SECRET-TOKEN-123', json_encode($log->toArray()));
    }

    public function test_a_failed_send_is_marked_failed_with_the_reason_and_no_secrets(): void
    {
        event(new MessageSending($this->rawEmail(), []));
        $this->assertSame(EmailLog::SENDING, EmailLog::firstOrFail()->status);

        EmailLogger::failed(new RuntimeException('SMTP down password=hunter2'));

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::FAILED, $log->status);
        $this->assertStringContainsString('SMTP down', (string) $log->error);
        $this->assertStringNotContainsString('hunter2', (string) $log->error);
    }

    public function test_a_failure_never_changes_an_email_that_was_already_delivered(): void
    {
        Mail::to('someone@example.com')->send(new TestMail);

        EmailLogger::failed(new RuntimeException('late error'));

        $this->assertSame(EmailLog::DELIVERED, EmailLog::firstOrFail()->status);
    }

    public function test_a_sending_row_with_no_answer_becomes_not_confirmed_after_a_while(): void
    {
        $log = $this->failedTicketLog(['status' => EmailLog::SENDING, 'error' => null]);
        $this->assertSame(EmailLog::SENDING, $log->effectiveStatus());

        $log->forceFill(['created_at' => now()->subMinutes(EmailLog::STALE_MINUTES + 1)])->save();

        $this->assertSame(EmailLog::UNCONFIRMED, $log->fresh()->effectiveStatus());
        $this->assertSame('Not confirmed', $log->fresh()->statusLabel());
    }

    // ---- the page -------------------------------------------------------------------------------

    public function test_only_the_maintenance_admin_may_open_the_page(): void
    {
        $log = $this->failedTicketLog();

        $this->actingAs($this->user('maintenance'))->get(route('admin.email-log.index'))->assertOk();
        $this->actingAs($this->user('maintenance'))->get(route('admin.email-log.show', $log->id))->assertOk();

        foreach (['requester', 'finance', 'president', 'custodian', 'depthead', 'treasurer'] as $name) {
            $this->actingAs($this->user($name))->get(route('admin.email-log.index'))->assertForbidden();
            $this->actingAs($this->user($name))->get(route('admin.email-log.show', $log->id))->assertForbidden();
            $this->actingAs($this->user($name))->post(route('admin.email-log.retry', $log->id))->assertForbidden();
        }
    }

    public function test_a_missing_id_is_a_403_for_a_person_without_access_and_a_404_for_the_admin(): void
    {
        $this->actingAs($this->user('requester'))->get(route('admin.email-log.show', 9999))->assertForbidden();
        $this->actingAs($this->user('maintenance'))->get(route('admin.email-log.show', 9999))->assertNotFound();
    }

    public function test_the_list_shows_rows_and_the_filters_work(): void
    {
        $this->failedTicketLog(['subject' => 'FAILED-ONE']);
        $this->failedTicketLog(['status' => EmailLog::DELIVERED, 'error' => null, 'subject' => 'DELIVERED-ONE', 'type' => 'closed']);

        $admin = $this->user('maintenance');

        $this->actingAs($admin)->get(route('admin.email-log.index'))
            ->assertOk()->assertSee('FAILED-ONE')->assertSee('DELIVERED-ONE');

        $this->actingAs($admin)->get(route('admin.email-log.index', ['status' => 'failed']))
            ->assertOk()->assertSee('FAILED-ONE')->assertDontSee('DELIVERED-ONE');

        $this->actingAs($admin)->get(route('admin.email-log.index', ['type' => 'closed']))
            ->assertOk()->assertSee('DELIVERED-ONE')->assertDontSee('FAILED-ONE');

        $this->actingAs($admin)->get(route('admin.email-log.index', ['q' => 'MT-2026-00001']))
            ->assertOk()->assertSee('FAILED-ONE');

        $this->actingAs($admin)->get(route('admin.email-log.index', ['status' => 'nonsense']))
            ->assertSessionHasErrors('status');
    }

    public function test_the_page_warns_when_the_mailer_does_not_really_send(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.email-log.index'))
            ->assertOk()->assertSee('not really sent');
    }

    public function test_the_detail_page_shows_the_error_and_a_sandboxed_preview(): void
    {
        $log = $this->failedTicketLog();

        $this->actingAs($this->user('maintenance'))->get(route('admin.email-log.show', $log->id))
            ->assertOk()
            ->assertSee('Connection refused')
            ->assertSee('sandbox=""', false)
            ->assertSee('Send again');
    }

    public function test_the_menu_link_is_shown_to_the_admin_only(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.audit-logs.index'))
            ->assertOk()->assertSee(route('admin.email-log.index'), false);

        $this->actingAs($this->user('president'))->get(route('admin.audit-logs.index'))
            ->assertOk()->assertDontSee(route('admin.email-log.index'), false);
    }

    // ---- send again -----------------------------------------------------------------------------

    public function test_a_failed_ticket_email_can_be_sent_again_and_the_same_row_is_updated(): void
    {
        $log = $this->failedTicketLog();

        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.email-log.retry', $log->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $log->refresh();
        $this->assertSame(EmailLog::DELIVERED, $log->status);
        $this->assertSame(2, $log->attempts);
        $this->assertNull($log->error);
        $this->assertSame(1, EmailLog::count());   // no second row
    }

    public function test_sending_again_is_written_to_the_audit_log(): void
    {
        $log = $this->failedTicketLog();

        $this->actingAs($this->user('maintenance'))->post(route('admin.email-log.retry', $log->id));

        $audit = AuditLog::where('action', AuditLogger::EMAIL_RETRIED)->firstOrFail();
        $this->assertSame($this->user('maintenance')->id, $audit->user_id);
        $this->assertStringContainsString('requester@lcctanauan.edu.ph', $audit->description);
        $this->assertStringNotContainsString('Ticket approved</p>', $audit->description);   // the body is never copied
    }

    public function test_a_delivered_email_and_an_email_without_a_body_cannot_be_sent_again(): void
    {
        $delivered = $this->failedTicketLog(['status' => EmailLog::DELIVERED, 'error' => null]);
        $noBody = $this->failedTicketLog(['type' => 'reset_password', 'body_html' => null]);

        $admin = $this->user('maintenance');

        $this->actingAs($admin)->post(route('admin.email-log.retry', $delivered->id))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.email-log.retry', $noBody->id))->assertSessionHas('error');

        $this->assertSame(1, $delivered->fresh()->attempts);
        $this->assertSame(EmailLog::FAILED, $noBody->fresh()->status);
        $this->assertFalse($noBody->fresh()->canRetry());
    }

    public function test_the_audit_action_name_fits_the_column(): void
    {
        $this->assertContains(AuditLogger::EMAIL_RETRIED, AuditLogger::actions());
        $this->assertLessThanOrEqual(50, strlen(AuditLogger::EMAIL_RETRIED));
    }
}
