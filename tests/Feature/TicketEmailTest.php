<?php

namespace Tests\Feature;

use App\Mail\TicketNotificationMail;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 13: the queued ticket emails (who gets what, never the person who acted, never an internal note,
 * nothing confidential in the text), all checked with Mail::fake().
 */
class TicketEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Budget is tight, do not tell the requester';

    /** The seeded accounts, in the order the helper lists them. */
    private const KEYS = [
        'requester', 'maintenance', 'maintenance2', 'maintenance3', 'president',
        'finance', 'custodian', 'depthead', 'treasurer',
    ];

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

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function ticket(bool $confidential = false): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
            'is_confidential' => $confidential,
        ]);
    }

    /** Approved by the President. */
    private function approved(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));

        return $ticket->refresh();
    }

    /** In Progress, assigned to "maintenance". */
    private function inProgress(): MaintenanceRequest
    {
        $ticket = $this->approved();
        TicketWorkflow::startWork($ticket, $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function waiting(): MaintenanceRequest
    {
        $ticket = $this->inProgress();
        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');

        return $ticket->refresh();
    }

    /** The queued emails of one kind. */
    private function mails(string $event)
    {
        return Mail::queued(TicketNotificationMail::class, fn ($mail) => $mail->event === $event);
    }

    /** Who received an email of this kind (account names, in the order of KEYS). */
    private function who(string $event): array
    {
        $mails = $this->mails($event);

        return array_values(array_filter(
            self::KEYS,
            fn (string $key) => $mails->contains(fn ($mail) => $mail->hasTo($key.'@lcctanauan.edu.ph')),
        ));
    }

    // ---- who gets what ----------------------------------------------------------------------------

    public function test_a_new_ticket_is_mailed_to_the_maintenance_staff_only(): void
    {
        Mail::fake();

        $this->ticket();

        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('submitted'));
    }

    public function test_sending_for_authorization_mails_the_president(): void
    {
        $ticket = $this->ticket();
        Mail::fake();

        TicketWorkflow::sendForAuthorization($ticket, $this->user('maintenance'));

        $this->assertSame(['president'], $this->who('sent_for_authorization'));
    }

    public function test_approval_mails_the_requester_and_the_staff_but_not_the_president(): void
    {
        $ticket = $this->ticket();
        Mail::fake();

        TicketWorkflow::authorize($ticket, $this->user('president'));

        $this->assertSame(['requester', 'maintenance', 'maintenance2', 'maintenance3'], $this->who('approved'));
    }

    public function test_a_disapproval_carries_the_reason(): void
    {
        $ticket = $this->ticket();
        Mail::fake();

        TicketWorkflow::disapprove($ticket, $this->user('president'), 'Not in this year budget');

        $this->assertSame(['requester', 'maintenance', 'maintenance2', 'maintenance3'], $this->who('disapproved'));
        $this->assertStringContainsString('Not in this year budget', $this->mails('disapproved')->first()->data['note']);
    }

    public function test_assigning_mails_the_new_assignee_and_the_requester(): void
    {
        $ticket = $this->approved();
        Mail::fake();

        TicketWorkflow::assign($ticket, $this->user('maintenance2'), $this->user('maintenance'));

        $this->assertSame(['requester', 'maintenance2'], $this->who('assigned'));
    }

    public function test_accepting_a_task_yourself_sends_work_started_but_no_assigned_email(): void
    {
        $ticket = $this->approved();
        Mail::fake();

        TicketWorkflow::startWork($ticket, $this->user('maintenance'));

        $this->assertSame([], $this->who('assigned'));
        $this->assertSame(['requester'], $this->who('in_progress'));
    }

    public function test_finishing_and_confirming_the_work_mails_the_right_people(): void
    {
        $ticket = $this->inProgress();
        Mail::fake();

        TicketWorkflow::markWorkCompleted($ticket, $this->user('maintenance'));
        $this->assertSame(['requester'], $this->who('work_completed'));

        TicketWorkflow::confirmResolved($ticket->refresh(), $this->user('requester'));
        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('closed'));   // not the requester who did it
    }

    public function test_a_question_goes_to_the_requester_and_the_answer_to_the_staff(): void
    {
        $ticket = $this->inProgress();
        Mail::fake();

        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');
        $this->assertSame(['requester'], $this->who('info_requested'));
        $this->assertStringContainsString('Which faucet is it?', $this->mails('info_requested')->first()->data['note']);

        TicketWorkflow::respondToInfo($ticket->refresh(), $this->user('requester'), 'The kitchen one');
        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('info_answered'));
    }

    public function test_reopening_mails_the_staff(): void
    {
        $ticket = $this->inProgress();
        TicketWorkflow::markWorkCompleted($ticket, $this->user('maintenance'));
        Mail::fake();

        TicketWorkflow::reopen($ticket->refresh(), $this->user('requester'), 'It leaks again');

        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('reopened'));
    }

    public function test_cancelling_by_the_requester_mails_the_staff_and_an_auto_close_also_the_requester(): void
    {
        $ticket = $this->ticket();
        Mail::fake();

        TicketWorkflow::cancel($ticket, $this->user('requester'), 'Not needed anymore');
        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('cancelled'));

        $waiting = $this->waiting();
        Mail::fake();

        $this->travelTo(now()->addDays(8));
        $this->artisan('tickets:autoclose')->assertExitCode(0);

        $this->assertSame(['requester', 'maintenance', 'maintenance2', 'maintenance3'], $this->who('cancelled'));
        $this->assertSame('cancelled', $waiting->fresh()->status->value);
    }

    public function test_escalating_by_hand_mails_the_president_and_the_other_staff(): void
    {
        $ticket = $this->inProgress();
        Mail::fake();

        TicketWorkflow::escalate($ticket, $this->user('maintenance'), 'Needs a budget decision');

        $this->assertSame(['maintenance2', 'maintenance3', 'president'], $this->who('escalated'));
        $this->assertStringContainsString('Needs a budget decision', $this->mails('escalated')->first()->data['note']);
    }

    public function test_the_escalation_command_mails_the_president_and_all_the_staff(): void
    {
        $ticket = $this->ticket();
        $this->travelTo($ticket->due_at->copy()->addMinute());
        Mail::fake();

        $this->artisan('tickets:escalate')->assertExitCode(0);

        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3', 'president'], $this->who('escalated'));
    }

    public function test_a_message_goes_to_the_other_side_and_carries_no_text(): void
    {
        $ticket = $this->inProgress();
        Mail::fake();

        TicketWorkflow::addReply($ticket, $this->user('maintenance'), 'We will come tomorrow morning');
        $this->assertSame(['requester'], $this->who('reply'));
        $this->assertStringNotContainsString('tomorrow morning', json_encode($this->mails('reply')->first()->data));

        Mail::fake();

        TicketWorkflow::addReply($ticket, $this->user('requester'), 'Thank you');
        $this->assertSame(['maintenance', 'maintenance2', 'maintenance3'], $this->who('reply'));
    }

    // ---- what must never be emailed ---------------------------------------------------------------

    public function test_an_internal_note_is_never_emailed(): void
    {
        $ticket = $this->inProgress();
        Mail::fake();

        TicketWorkflow::addReply($ticket, $this->user('president'), self::SECRET, [], internal: true);

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.replies.store', $ticket), ['body' => self::SECRET, 'is_internal' => '1'])
            ->assertSessionHasNoErrors();

        Mail::assertNothingQueued();
    }

    public function test_a_confidential_ticket_email_has_no_text_from_the_ticket(): void
    {
        Mail::fake();

        $this->ticket(confidential: true);

        $mail = $this->mails('submitted')->first();
        $this->assertNotNull($mail);
        $this->assertTrue($mail->data['confidential']);
        $this->assertSame([], $mail->data['details']);
        $this->assertNull($mail->data['note']);

        $html = $mail->render();
        $this->assertStringContainsString('confidential', $html);
        $this->assertStringNotContainsString('Leaking faucet', $html);
        $this->assertStringNotContainsString('Grade 5 Room 204', $html);
    }

    public function test_inactive_users_get_no_email(): void
    {
        User::whereKey($this->user('maintenance3')->id)->update(['is_active' => false]);
        Mail::fake();

        $this->ticket();

        $this->assertSame(['maintenance', 'maintenance2'], $this->who('submitted'));
    }

    public function test_the_switch_turns_every_ticket_email_off(): void
    {
        config(['school.ticket_emails' => false]);
        Mail::fake();

        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::addReply($ticket->refresh(), $this->user('requester'), 'Hello');

        Mail::assertNothingQueued();
    }

    // ---- the email itself -------------------------------------------------------------------------

    public function test_the_emails_are_queued_and_the_page_text_is_rendered(): void
    {
        Mail::fake();

        $ticket = $this->ticket();

        Mail::assertQueued(TicketNotificationMail::class);

        $mail = $this->mails('submitted')->first();
        $this->assertInstanceOf(ShouldQueue::class, $mail);
        $this->assertStringContainsString($ticket->ticket_no, $mail->data['subject']);

        $html = $mail->render();
        $this->assertStringContainsString($ticket->ticket_no, $html);
        $this->assertStringContainsString('Open ticket', $html);
        $this->assertStringContainsString(route('tickets.show', $ticket), $html);
        $this->assertStringContainsString('Grade 5 Room 204', $html);
        $this->assertStringContainsString('Leaking faucet', $html);
    }

    public function test_a_failing_email_never_blocks_the_ticket_action(): void
    {
        $ticket = $this->ticket();

        // the real mailer is used here (array driver); break the view so rendering throws
        config(['mail.brand' => null]);

        $approved = TicketWorkflow::authorize($ticket, $this->user('president'));

        $this->assertSame('approved', $approved->status->value);
    }
}
