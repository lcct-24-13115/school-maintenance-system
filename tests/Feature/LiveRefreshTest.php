<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 15: dashboard hero (greeting + Last update) and the /live signature behind the auto-refresh. */
class LiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function make(User $requester, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
        ]);
    }

    private function sig(User $user, array $query = []): string
    {
        return $this->actingAs($user)->getJson(route('live', $query))->assertOk()->json('sig');
    }

    public function test_a_guest_gets_401_and_is_not_redirected(): void
    {
        $this->getJson(route('live'))->assertUnauthorized();
    }

    public function test_the_signature_is_stable_until_something_changes(): void
    {
        $staff = $this->user('maintenance');
        $ticket = $this->make($this->user('requester'));

        $first = $this->sig($staff);
        $this->assertNotSame('', $first);
        $this->assertSame($first, $this->sig($staff));   // nothing happened: same answer

        $this->make($this->user('requester'));
        $second = $this->sig($staff);
        $this->assertNotSame($first, $second);           // a new ticket

        $ticket->update(['status' => TicketStatus::Cancelled]);
        $this->assertNotSame($second, $this->sig($staff));   // a finished ticket
    }

    public function test_a_ticket_the_user_cannot_see_never_moves_their_signature(): void
    {
        $requester = $this->user('requester');
        $before = $this->sig($requester);

        $this->make($this->user('requester2'));
        $this->make($this->user('requester2'), ['is_confidential' => true]);

        $this->assertSame($before, $this->sig($requester));

        $this->make($requester);
        $this->assertNotSame($before, $this->sig($requester));
    }

    public function test_the_ticket_signature_follows_the_conversation_but_not_internal_notes_for_the_requester(): void
    {
        $requester = $this->user('requester');
        $staff = $this->user('maintenance');
        $ticket = $this->make($requester);

        $reqBefore = $this->sig($requester, ['t' => $ticket->id]);
        $staffBefore = $this->sig($staff, ['t' => $ticket->id]);
        $this->assertNotSame('', $reqBefore);

        TicketWorkflow::addReply($ticket, $staff, 'Internal only', [], internal: true);
        $this->assertSame($reqBefore, $this->sig($requester, ['t' => $ticket->id]));      // hidden from the requester
        $this->assertNotSame($staffBefore, $this->sig($staff, ['t' => $ticket->id]));     // staff see their own note

        $afterNote = $this->sig($requester, ['t' => $ticket->id]);
        TicketWorkflow::addReply($ticket, $staff, 'Hello, we are on it', []);
        $this->assertNotSame($afterNote, $this->sig($requester, ['t' => $ticket->id]));   // a normal message
    }

    public function test_a_hidden_or_missing_ticket_gives_an_empty_signature(): void
    {
        $secret = $this->make($this->user('requester2'), ['is_confidential' => true]);

        $this->assertSame('', $this->sig($this->user('requester'), ['t' => $secret->id]));
        $this->assertSame('', $this->sig($this->user('requester'), ['t' => 999999]));
    }

    public function test_the_dashboard_has_the_greeting_last_update_and_the_live_script(): void
    {
        $req = $this->user('requester');
        $page = $this->actingAs($req)->get(route('requester.dashboard'))->assertOk()
            ->assertSee('welcome back')->assertSee('Last update')->assertSee('No tickets yet')
            ->assertSee('id="lcct-live"', false)->assertSee(route('live'), false);

        $this->make($req);
        $this->actingAs($req)->get(route('requester.dashboard'))->assertOk()
            ->assertDontSee('No tickets yet')->assertViewHas('lastUpdate', fn ($d) => $d !== null);
    }

    public function test_the_lists_and_the_ticket_page_carry_the_script_and_the_ticket_page_polls_its_own_ticket(): void
    {
        $req = $this->user('requester');
        $ticket = $this->make($req);

        $this->actingAs($req)->get(route('tickets.index'))->assertOk()->assertSee('id="lcct-live"', false);
        $this->actingAs($req)->get(route('tickets.history'))->assertOk()->assertSee('id="lcct-live"', false);
        $this->actingAs($req)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('id="lcct-live"', false)->assertSee(route('live', ['t' => $ticket->id]), false);
    }

    public function test_the_script_can_be_turned_off(): void
    {
        config(['school.live_refresh_seconds' => 0]);

        $this->actingAs($this->user('requester'))->get(route('requester.dashboard'))->assertOk()
            ->assertDontSee('id="lcct-live"', false);
    }
}
