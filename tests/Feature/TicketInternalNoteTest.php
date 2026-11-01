<?php

namespace Tests\Feature;

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
 * Phase 11: internal notes. Written and read by staff only; the requester never sees one, in the page, in the
 * relation, in the query scope, in a file link or in an alert.
 */
class TicketInternalNoteTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const SECRET = 'Requester keeps lying about the room number';

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

    private function ticket(string $requester = 'requester'): MaintenanceRequest
    {
        $user = $this->user($requester);

        return TicketWorkflow::open($user, [
            'department_id' => $user->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
    }

    private function inProgress(string $requester = 'requester'): MaintenanceRequest
    {
        $ticket = $this->ticket($requester);
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::startWork($ticket->refresh(), $this->user('maintenance'));

        return $ticket->refresh();
    }

    private function say(MaintenanceRequest $ticket, string $who, array $data = [])
    {
        return $this->actingAs($this->user($who))->post(route('tickets.replies.store', $ticket), $data);
    }

    private function note(MaintenanceRequest $ticket, string $who = 'maintenance', string $text = self::SECRET)
    {
        return $this->say($ticket, $who, ['body' => $text, 'is_internal' => '1']);
    }

    // ---- writing --------------------------------------------------------------------------------

    public function test_staff_write_an_internal_note_and_it_is_marked_internal(): void
    {
        $ticket = $this->inProgress();

        $this->note($ticket)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->say($ticket, 'maintenance', ['body' => 'We are on it.'])->assertSessionHasNoErrors();

        $this->assertSame(2, TicketReply::where('request_id', $ticket->id)->count());
        $this->assertTrue(TicketReply::where('body', self::SECRET)->firstOrFail()->is_internal);
        $this->assertFalse(TicketReply::where('body', 'We are on it.')->firstOrFail()->is_internal);
    }

    public function test_the_requester_cannot_write_an_internal_note(): void
    {
        $ticket = $this->inProgress();

        $this->note($ticket, 'requester', 'sneaky')->assertForbidden();
        $this->assertSame(0, TicketReply::count());

        // and the workflow itself refuses too
        $this->expectException(DomainException::class);
        TicketWorkflow::addReply($ticket, $this->user('requester'), 'sneaky', [], internal: true);
    }

    public function test_view_only_roles_cannot_write_internal_notes(): void
    {
        $ticket = $this->inProgress();

        foreach (['finance', 'depthead'] as $who) {
            $this->note($ticket, $who, 'nope')->assertForbidden();
        }

        $this->assertSame(0, TicketReply::count());
    }

    public function test_an_internal_note_carries_no_files(): void
    {
        $ticket = $this->inProgress();
        $png = UploadedFile::fake()->createWithContent('leak.png', base64_decode(self::PNG));

        $this->say($ticket, 'maintenance', ['body' => 'Photo', 'is_internal' => '1', 'files' => [$png]])
            ->assertSessionHasErrorsIn('thread', 'body');

        $this->assertSame(0, TicketReply::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---- hiding ---------------------------------------------------------------------------------

    public function test_the_page_shows_the_note_to_staff_and_hides_it_from_the_requester(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => 'Is anyone coming?']);
        $this->note($ticket);

        foreach (['maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk()
                ->assertSee(self::SECRET)->assertSee('Internal note')->assertSee('is_internal', false);
        }

        $page = $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertOk();
        $page->assertSee('Is anyone coming?')
            ->assertDontSee(self::SECRET)
            ->assertDontSee('Internal note')
            ->assertDontSee('is_internal', false)
            ->assertSee('Conversation')->assertSee('(1)');
    }

    public function test_people_who_only_read_never_see_internal_notes(): void
    {
        $ticket = $this->inProgress();
        $this->note($ticket);

        foreach (['finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.show', $ticket))->assertOk()
                ->assertDontSee(self::SECRET);
        }
    }

    public function test_a_staff_member_who_is_the_requester_of_the_ticket_does_not_see_notes_about_it(): void
    {
        // maintenance2 files the ticket; maintenance works on it and writes the note
        $ticket = $this->ticket('maintenance2');
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::startWork($ticket->refresh(), $this->user('maintenance'));
        $this->note($ticket->refresh());

        $this->actingAs($this->user('maintenance2'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee(self::SECRET);
        $this->note($ticket, 'maintenance2', 'my own note')->assertForbidden();
        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee(self::SECRET);
    }

    public function test_the_query_layer_hides_the_note_even_without_the_page(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'requester', ['body' => 'Public one']);
        $this->note($ticket);

        $requester = $this->user('requester');
        $staff = $this->user('maintenance');

        // the plain relation can never contain a note: code that forgets about Phase 11 cannot leak it
        $this->assertSame(['Public one'], $ticket->replies()->pluck('body')->all());
        $this->assertSame(['Public one'], $ticket->fresh()->replies->pluck('body')->all());
        $this->assertSame(['Public one'], TicketReply::query()->public()->pluck('body')->all());

        // the scope for a person
        $this->assertSame(['Public one'], $ticket->conversationFor($requester)->pluck('body')->all());
        $this->assertSame(['Public one', self::SECRET], $ticket->conversationFor($staff)->pluck('body')->all());
        $this->assertSame(1, TicketReply::query()->where('request_id', $ticket->id)->visibleTo($requester, $ticket)->count());
        $this->assertSame(2, TicketReply::query()->where('request_id', $ticket->id)->visibleTo($staff, $ticket)->count());
    }

    public function test_a_file_of_an_internal_note_is_not_reachable_by_the_requester(): void
    {
        // internal notes cannot be created with files, so make the worst case by hand
        $ticket = $this->inProgress();
        $this->note($ticket);
        $reply = TicketReply::firstOrFail();

        Storage::disk('local')->put('ticket-attachments/x.png', base64_decode(self::PNG));
        $file = RequestAttachment::create([
            'request_id' => $ticket->id, 'reply_id' => $reply->id, 'uploaded_by' => $this->user('maintenance')->id,
            'path' => 'ticket-attachments/x.png', 'disk' => 'local', 'kind' => RequestAttachment::KIND_REPLY,
            'original_name' => 'x.png', 'mime_type' => 'image/png', 'size_bytes' => 70,
        ]);

        $this->actingAs($this->user('requester'))->get($file->url())->assertNotFound();
        $this->actingAs($this->user('maintenance'))->get($file->url())->assertOk();
    }

    // ---- alerts ---------------------------------------------------------------------------------

    public function test_the_requester_is_never_alerted_about_an_internal_note(): void
    {
        $ticket = $this->inProgress();   // assigned to maintenance
        $this->say($ticket, 'president', ['body' => 'Please check the budget', 'is_internal' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->user('requester')->notifications()->count());

        $events = $this->user('maintenance')->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null);
        $this->assertTrue($events->contains('ticket_internal_note'));
        $this->assertSame(0, $this->user('president')->notifications()->count());   // not the writer

        // the alert text never contains the note itself
        $this->assertStringNotContainsString('budget', json_encode($this->user('maintenance')->notifications()->get()->pluck('data')));
    }

    public function test_an_ordinary_message_still_alerts_the_requester(): void
    {
        $ticket = $this->inProgress();
        $this->say($ticket, 'maintenance', ['body' => 'Done tomorrow']);

        $this->assertSame(1, $this->user('requester')->notifications()->count());
    }
}
