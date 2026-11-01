<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 10: the reply thread on the ticket page (text + files).
 * Reading follows the ticket's visibility; writing is TicketPolicy@reply; a finished ticket takes no more replies.
 */
class TicketReplyTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG, so finfo detects it from the bytes like a real upload. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    private function inProgress(): MaintenanceRequest
    {
        $ticket = $this->ticket();
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::startWork($ticket->refresh(), $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function say(MaintenanceRequest $ticket, string $who, array $data = [])
    {
        return $this->actingAs($this->user($who))->post(route('tickets.replies.store', $ticket), $data);
    }

    private function png(string $name = 'leak.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    // ---- the conversation -----------------------------------------------------------------------

    public function test_the_requester_and_staff_talk_on_the_ticket_page(): void
    {
        $ticket = $this->inProgress();

        $this->say($ticket, 'requester', ['body' => 'The faucet is in the boys restroom.'])
            ->assertRedirect(route('tickets.show', $ticket).'#replies')
            ->assertSessionHasNoErrors();
        $this->say($ticket, 'maintenance', ['body' => 'Thanks, we will bring a new washer.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $ticket->replies()->count());
        $this->assertSame($this->user('requester')->id, $ticket->replies()->first()->user_id);

        foreach (['requester', 'maintenance'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk()
                ->assertSeeInOrder(['The faucet is in the boys restroom.', 'Thanks, we will bring a new washer.']);
        }
    }

    public function test_a_reply_needs_text_or_a_file_and_has_a_length_limit(): void
    {
        $ticket = $this->inProgress();

        $this->say($ticket, 'requester', ['body' => '   '])->assertSessionHasErrorsIn('thread', 'body');
        $this->say($ticket, 'requester', [])->assertSessionHasErrorsIn('thread', 'body');
        $this->say($ticket, 'requester', ['body' => str_repeat('a', TicketReply::BODY_MAX + 1)])
            ->assertSessionHasErrorsIn('thread', 'body');
        $this->assertSame(0, $ticket->replies()->count());

        // exactly the limit is fine
        $this->say($ticket, 'requester', ['body' => str_repeat('a', TicketReply::BODY_MAX)])->assertSessionHasNoErrors();
        $this->assertSame(1, $ticket->replies()->count());
    }

    public function test_the_text_is_shown_as_plain_text(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => '<script>alert(1)</script> <b>bold</b>']);

        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_reply_does_not_change_the_status_or_the_history(): void
    {
        $ticket = $this->inProgress();
        $logs = $ticket->statusLogs()->count();

        $this->say($ticket, 'requester', ['body' => 'Hello']);
        $this->say($ticket, 'maintenance', ['body' => 'Hi']);

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame($logs, $ticket->statusLogs()->count());
    }

    // ---- who may read / write -------------------------------------------------------------------

    public function test_nobody_who_cannot_see_the_ticket_can_read_or_write(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => 'A private detail']);

        // another requester: the ticket does not exist for them (404, never 403)
        $this->actingAs($this->user('requester2'))->get(route('tickets.show', $ticket))->assertNotFound();
        $this->say($ticket, 'requester2', ['body' => 'Let me in'])->assertNotFound();
        $this->assertSame(1, $ticket->replies()->count());

        // finance sees every non-confidential ticket but NOT a confidential one
        $secret = $this->ticket(confidential: true);
        $this->say($secret, 'requester', ['body' => 'Confidential matter']);
        $this->actingAs($this->user('finance'))->get(route('tickets.show', $secret))->assertNotFound();
        $this->say($secret, 'finance', ['body' => 'Peeking'])->assertNotFound();
        $this->assertSame(1, $secret->replies()->count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $ticket = $this->inProgress();

        $this->post(route('tickets.replies.store', $ticket), ['body' => 'x'])->assertRedirect(route('login'));
        $this->assertSame(0, $ticket->replies()->count());
    }

    public function test_view_only_roles_read_the_conversation_but_cannot_write(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => 'Please hurry']);

        foreach (['finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk()
                ->assertSee('Please hurry')
                ->assertDontSee(route('tickets.replies.store', $ticket));
            $this->say($ticket, $who, ['body' => 'I should not be able to write'])->assertForbidden();
        }

        $this->assertSame(1, $ticket->replies()->count());
    }

    public function test_the_requester_the_assignee_and_the_working_roles_may_write(): void
    {
        $ticket = $this->inProgress();

        foreach (['requester', 'maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk()
                ->assertSee(route('tickets.replies.store', $ticket));
            $this->say($ticket, $who, ['body' => "From {$who}"])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $ticket->replies()->count());
    }

    // ---- files ----------------------------------------------------------------------------------

    public function test_a_reply_can_carry_files_that_follow_the_ticket_visibility(): void
    {
        $ticket = $this->inProgress();

        $this->say($ticket, 'requester', ['body' => 'Photo attached', 'files' => [$this->png()]])
            ->assertSessionHasNoErrors();

        $reply = $ticket->replies()->firstOrFail();
        $file = $reply->attachments()->firstOrFail();
        $this->assertSame(RequestAttachment::KIND_REPLY, $file->kind);
        $this->assertSame($ticket->id, $file->request_id);
        $this->assertSame($this->user('requester')->id, $file->uploaded_by);
        $this->assertSame('image/png', $file->mime_type);
        Storage::disk('local')->assertExists($file->path);

        // shown in the conversation, not in the ticket's own Attachments list, and never the board photo
        $page = $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk();
        $page->assertSee('leak.png')->assertSee('Attachments <span', false)->assertSee('(0)');
        $this->assertNull($ticket->fresh()->load('attachments')->photoUrl());

        // opened through the authorized route: the people who see the ticket, nobody else
        $this->actingAs($this->user('maintenance'))->get($file->url())->assertOk();
        $this->actingAs($this->user('requester'))->get($file->downloadUrl())->assertOk();
        $this->actingAs($this->user('requester2'))->get($file->url())->assertNotFound();
    }

    public function test_a_file_alone_is_a_valid_reply(): void
    {
        $ticket = $this->inProgress();

        $this->say($ticket, 'maintenance', ['files' => [$this->png('after.png')]])->assertSessionHasNoErrors();

        $reply = $ticket->replies()->firstOrFail();
        $this->assertNull($reply->body);
        $this->assertSame(1, $reply->attachments()->count());
    }

    public function test_a_refused_file_leaves_no_reply_and_no_file_behind(): void
    {
        $ticket = $this->inProgress();
        $exe = UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00\x03\x00\x00\x00");

        $this->say($ticket, 'requester', ['body' => 'Here it is', 'files' => [$this->png(), $exe]])
            ->assertSessionHasErrorsIn('thread');

        $this->assertSame(0, $ticket->replies()->count());
        $this->assertSame(0, RequestAttachment::query()->where('kind', RequestAttachment::KIND_REPLY)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_failing_in_the_workflow_rolls_the_whole_reply_back(): void
    {
        $ticket = $this->inProgress();
        $exe = UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00\x03\x00\x00\x00");

        try {
            TicketWorkflow::addReply($ticket, $this->user('requester'), 'With a bad file', [$this->png(), $exe]);
            $this->fail('A refused file must stop the reply.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(0, $ticket->replies()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_add_files_form_can_never_choose_the_reply_kind(): void
    {
        $ticket = $this->inProgress();

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.attachments.store', $ticket), ['files' => [$this->png()], 'kind' => RequestAttachment::KIND_REPLY])
            ->assertSessionHasErrors('kind');
        $this->assertSame(0, $ticket->attachments()->count());
    }

    // ---- state rules ----------------------------------------------------------------------------

    public function test_a_finished_ticket_takes_no_more_replies(): void
    {
        $ticket = $this->ticket();
        $this->say($ticket, 'requester', ['body' => 'Before cancelling']);
        TicketWorkflow::cancel($ticket->refresh(), $this->user('requester'), 'Not needed any more');

        $this->say($ticket, 'requester', ['body' => 'After cancelling'])->assertSessionHasErrorsIn('thread', 'body');
        $this->assertSame(1, $ticket->replies()->count());

        $page = $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk();
        $page->assertSee('Before cancelling')->assertSee('The conversation is closed.')
            ->assertDontSee(route('tickets.replies.store', $ticket));
    }

    public function test_a_waiting_ticket_can_be_talked_about_but_stays_waiting_until_answered(): void
    {
        $ticket = $this->inProgress();
        TicketWorkflow::requestInfo($ticket, $this->user('maintenance'), 'Which faucet is it?');

        $this->say($ticket, 'requester', ['body' => 'The one on the left.'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::WaitingForUser, $ticket->fresh()->status);

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Answer it in the')
            ->assertSee(route('tickets.respond-info', $ticket));
    }

    // ---- alerts ---------------------------------------------------------------------------------

    private function alerts(User $user): int
    {
        return $user->notifications()->get()
            ->filter(fn ($n) => ($n->data['event'] ?? null) === 'ticket_reply')->count();
    }

    public function test_the_other_side_is_alerted_but_never_the_writer(): void
    {
        $ticket = $this->inProgress();   // assigned to maintenance

        $this->say($ticket, 'requester', ['body' => 'Any news?']);
        $this->assertSame(1, $this->alerts($this->user('maintenance')));
        $this->assertSame(0, $this->alerts($this->user('requester')));
        $this->assertSame(0, $this->alerts($this->user('requester2')));   // cannot see the ticket

        $this->say($ticket, 'maintenance', ['body' => 'Coming tomorrow.']);
        $this->assertSame(1, $this->alerts($this->user('requester')));
        $this->assertSame(1, $this->alerts($this->user('maintenance')));  // still only the first one
    }

    // ---- structure ------------------------------------------------------------------------------

    public function test_the_conversation_goes_with_the_ticket_when_it_is_removed_for_good(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => 'Bye', 'files' => [$this->png()]]);
        $this->assertSame(1, TicketReply::count());

        $ticket->delete();   // a soft delete keeps everything, like the ticket's own files
        $this->assertSame(1, TicketReply::count());

        $ticket->forceDelete();

        $this->assertSame(0, TicketReply::count());
        $this->assertSame(0, RequestAttachment::query()->where('kind', RequestAttachment::KIND_REPLY)->count());
    }
}
