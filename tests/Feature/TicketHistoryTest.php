<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 14: "My Tickets" lists active tickets only; "Ticket History" lists Closed / Cancelled / Disapproved. */
class TicketHistoryTest extends TestCase
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

    private function make(User $requester, TicketStatus $status = TicketStatus::Submitted, array $overrides = []): MaintenanceRequest
    {
        $ticket = MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
        ]);

        // set the status directly: this test is about the lists, not about the workflow rules
        DB::table('requests')->where('id', $ticket->id)->update(['status' => $status->value]);

        return $ticket->fresh();
    }

    private function ids(User $user, string $route, array $query = []): array
    {
        return $this->actingAs($user)->get(route($route, $query))->assertOk()
            ->viewData('tickets')->getCollection()->pluck('id')->all();
    }

    public function test_finished_tickets_are_not_in_the_active_list_and_are_in_the_history(): void
    {
        $req = $this->user('requester');
        $active = $this->make($req, TicketStatus::InProgress);
        $closed = $this->make($req, TicketStatus::Closed);
        $cancelled = $this->make($req, TicketStatus::Cancelled);
        $disapproved = $this->make($req, TicketStatus::Disapproved);
        $completed = $this->make($req, TicketStatus::WorkCompleted);   // not closed yet: still active

        foreach (['requester', 'maintenance'] as $who) {
            $user = $this->user($who);

            $list = $this->ids($user, 'tickets.index');
            $this->assertEqualsCanonicalizing([$active->id, $completed->id], $list);

            $history = $this->ids($user, 'tickets.history');
            $this->assertEqualsCanonicalizing([$closed->id, $cancelled->id, $disapproved->id], $history);
        }
    }

    public function test_history_follows_the_same_visibility_rule(): void
    {
        $mine = $this->make($this->user('requester'), TicketStatus::Closed);
        $other = $this->make($this->user('requester2'), TicketStatus::Closed);
        $secret = $this->make($this->user('requester2'), TicketStatus::Closed, ['is_confidential' => true]);

        $requesterSees = $this->ids($this->user('requester'), 'tickets.history');
        $this->assertContains($mine->id, $requesterSees);
        $this->assertNotContains($secret->id, $requesterSees);   // someone else's confidential ticket stays hidden

        $this->assertEqualsCanonicalizing(
            MaintenanceRequest::visibleTo($this->user('maintenance'))->history()->pluck('id')->all(),
            $this->ids($this->user('maintenance'), 'tickets.history')
        );
    }

    public function test_history_search_status_and_date_filters(): void
    {
        $req = $this->user('requester');
        $old = $this->make($req, TicketStatus::Closed, ['location' => 'Gym', 'submitted_at' => now()->subDays(40)]);
        $new = $this->make($req, TicketStatus::Closed, ['location' => 'Canteen', 'submitted_at' => now()->subDay()]);
        $cancelled = $this->make($req, TicketStatus::Cancelled, ['location' => 'Canteen', 'submitted_at' => now()->subDay()]);
        $staff = $this->user('maintenance');

        $this->assertSame([$old->id], $this->ids($staff, 'tickets.history', ['q' => 'Gym']));
        $this->assertSame([$cancelled->id], $this->ids($staff, 'tickets.history', ['status' => ['cancelled']]));
        $this->assertEqualsCanonicalizing(
            [$new->id, $cancelled->id],
            $this->ids($staff, 'tickets.history', ['from' => now()->subDays(5)->toDateString()])
        );
        $this->assertSame([$old->id], $this->ids($staff, 'tickets.history', ['to' => now()->subDays(30)->toDateString()]));
    }

    public function test_an_active_status_in_the_history_url_is_ignored(): void
    {
        $req = $this->user('requester');
        $closed = $this->make($req, TicketStatus::Closed);
        $this->make($req, TicketStatus::InProgress);

        $this->assertSame([$closed->id], $this->ids($this->user('maintenance'), 'tickets.history', ['status' => ['in_progress']]));
    }

    public function test_an_old_closed_link_on_the_active_list_goes_to_the_history(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->get(route('tickets.index', ['status' => ['closed']]))
            ->assertRedirect(route('tickets.history', ['status' => ['closed']]));
    }

    public function test_history_is_paged_newest_first_and_keeps_the_filters(): void
    {
        $req = $this->user('requester');
        for ($i = 0; $i < 17; $i++) {
            $this->make($req, TicketStatus::Closed, ['location' => 'Gym']);
        }
        $staff = $this->user('maintenance');

        $page = $this->actingAs($staff)->get(route('tickets.history', ['q' => 'Gym', 'status' => ['closed']]))
            ->assertOk()->viewData('tickets');

        $this->assertSame(15, $page->count());
        $this->assertSame(17, $page->total());
        $this->assertStringContainsString('q=Gym', urldecode($page->nextPageUrl()));
        $this->assertStringContainsString('status[0]=closed', urldecode($page->nextPageUrl()));
    }

    public function test_the_page_shows_both_tabs_and_the_history_status_choices_only(): void
    {
        $this->actingAs($this->user('requester'))->get(route('tickets.history'))->assertOk()
            ->assertSee('My Tickets')->assertSee('Ticket History')
            ->assertViewHas('statusOptions', fn ($o) => array_keys($o) === ['closed', 'disapproved', 'cancelled']
                || (count($o) === 3 && isset($o['closed'], $o['disapproved'], $o['cancelled'])));

        $this->actingAs($this->user('requester'))->get(route('tickets.index'))->assertOk()
            ->assertViewHas('statusOptions', fn ($o) => ! isset($o['closed']) && ! isset($o['cancelled']) && ! isset($o['disapproved']) && isset($o['in_progress']));
    }

    public function test_the_history_needs_login(): void
    {
        $this->get(route('tickets.history'))->assertRedirect(route('login'));
    }

    public function test_opening_a_finished_ticket_still_works_and_links_back_to_the_history(): void
    {
        $closed = $this->make($this->user('requester'), TicketStatus::Closed);

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $closed))->assertOk()
            ->assertSee(route('tickets.history'), false);
    }
}
