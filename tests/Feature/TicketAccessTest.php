<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Phase 2: permission matrix, visibility (incl. confidential), ticket list + search + filters, ticket detail. */
class TicketAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedBase(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    /** user('requester') => requester@lcctanauan.edu.ph */
    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function category(int $skip = 0): Category
    {
        return Category::orderBy('id')->skip($skip)->firstOrFail();
    }

    private function make(User $requester, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => $this->category()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Test ticket',
        ]);
    }

    /** Ids the model scope lets this user see. */
    private function visibleIds(User $user): array
    {
        return MaintenanceRequest::visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    /** Ids on the page /tickets, in the order shown. */
    private function listIds(User $user, array $query = []): array
    {
        return $this->actingAs($user)->get(route('tickets.index', $query))->assertOk()
            ->viewData('tickets')->getCollection()->pluck('id')->all();
    }

    // ---- permission matrix ----------------------------------------------------------------------

    public function test_can_do_reads_the_permission_matrix(): void
    {
        $this->seedBase();

        $this->assertTrue($this->user('maintenance')->can_do('assign'));
        $this->assertTrue($this->user('maintenance')->can_do('view_confidential'));
        $this->assertTrue($this->user('finance')->can_do('view_all_tickets'));
        $this->assertFalse($this->user('finance')->can_do('view_confidential'));
        $this->assertTrue($this->user('president')->can_do('authorize'));
        $this->assertFalse($this->user('maintenance')->can_do('authorize'));
        foreach (['requester', 'depthead', 'treasurer', 'custodian'] as $name) {
            $this->assertFalse($this->user($name)->can_do('view_all_tickets'), $name);
            $this->assertFalse($this->user($name)->can_do('flag_emergency'), $name);
        }
        $this->assertFalse($this->user('maintenance')->can_do('no_such_capability'));
    }

    public function test_the_matrix_is_config_driven_and_inactive_users_can_do_nothing(): void
    {
        $this->seedBase();
        $finance = $this->user('finance');
        $this->assertFalse($finance->can_do('authorize'));

        config(['permissions.finance' => ['authorize']]);
        $this->assertTrue($finance->can_do('authorize'));

        $finance->is_active = false;
        $this->assertFalse($finance->can_do('authorize'));
        $this->assertSame([], $this->visibleIds($finance));
    }

    public function test_policy_abilities_follow_the_matrix(): void
    {
        $this->seedBase();
        $ticket = $this->make($this->user('requester'));

        $this->assertTrue(Gate::forUser($this->user('maintenance'))->allows('flagEmergency', $ticket));
        $this->assertTrue(Gate::forUser($this->user('president'))->allows('flagEmergency', $ticket));
        $this->assertFalse(Gate::forUser($this->user('finance'))->allows('flagEmergency', $ticket));
        $this->assertFalse(Gate::forUser($this->user('requester'))->allows('flagEmergency', $ticket));

        $this->assertTrue(Gate::forUser($this->user('president'))->allows('authorize', $ticket));
        $this->assertFalse(Gate::forUser($this->user('maintenance'))->allows('authorize', $ticket));

        $this->assertTrue(Gate::forUser($this->user('maintenance'))->allows('assign', $ticket));
        $this->assertTrue(Gate::forUser($this->user('maintenance'))->allows('work', $ticket));
        $this->assertTrue(Gate::forUser($this->user('maintenance'))->allows('close', $ticket));
        $this->assertTrue(Gate::forUser($this->user('maintenance'))->allows('addAttachment', $ticket));
        $this->assertFalse(Gate::forUser($this->user('requester'))->allows('work', $ticket));
    }

    // ---- visibility matrix ----------------------------------------------------------------------

    public function test_visibility_matrix(): void
    {
        $this->seedBase();
        $req = $this->user('requester');      // Elementary
        $req2 = $this->user('requester2');    // High School

        $t1 = $this->make($req);
        $t2 = $this->make($req2);
        $t3 = $this->make($req, ['is_confidential' => true]);
        $t4 = $this->make($req2, ['is_confidential' => true]);

        $this->assertEquals([$t1->id, $t3->id], $this->visibleIds($req), 'requester: own only');
        $this->assertEquals([$t2->id, $t4->id], $this->visibleIds($req2), 'requester2: own only');
        $this->assertEquals([$t1->id], $this->visibleIds($this->user('depthead')), 'dept head: own department, not confidential');
        $this->assertEquals([$t1->id, $t2->id], $this->visibleIds($this->user('finance')), 'finance: not confidential');

        $all = [$t1->id, $t2->id, $t3->id, $t4->id];
        $this->assertEquals($all, $this->visibleIds($this->user('maintenance')));
        $this->assertEquals($all, $this->visibleIds($this->user('president')));
        $this->assertSame([], $this->visibleIds($this->user('treasurer')));
        $this->assertSame([], $this->visibleIds($this->user('custodian')));
    }

    public function test_a_confidential_ticket_is_404_for_everyone_who_may_not_see_it(): void
    {
        $this->seedBase();
        $secret = $this->make($this->user('requester'), ['is_confidential' => true]);

        foreach (['requester2', 'finance', 'depthead', 'treasurer', 'custodian'] as $name) {
            $this->actingAs($this->user($name))->get(route('tickets.show', $secret))->assertNotFound();
        }
        foreach (['requester', 'maintenance', 'president'] as $name) {
            $this->actingAs($this->user($name))->get(route('tickets.show', $secret))->assertOk();
        }

        // a ticket that does not exist answers the same way, so existence is not leaked
        $this->actingAs($this->user('finance'))->get(route('tickets.show', 999999))->assertNotFound();
    }

    public function test_the_assignee_can_see_a_confidential_ticket_even_without_view_confidential(): void
    {
        $this->seedBase();
        config(['permissions.maintenance_admin' => ['view_all_tickets', 'work']]);   // no view_confidential

        $assigned = $this->make($this->user('requester2'), ['is_confidential' => true, 'assigned_to' => $this->user('maintenance2')->id]);
        $other = $this->make($this->user('requester'), ['is_confidential' => true]);

        $this->assertEquals([$assigned->id], $this->visibleIds($this->user('maintenance2')));
        $this->assertSame([], $this->visibleIds($this->user('maintenance')));
        $this->actingAs($this->user('maintenance'))->get(route('tickets.show', $other))->assertNotFound();
    }

    public function test_the_scope_and_the_policy_always_agree(): void
    {
        $this->seed();   // 40 sample tickets, 3 confidential

        $variants = [
            [],
            ['permissions.maintenance_admin' => ['view_all_tickets', 'work']],
            ['permissions.finance' => ['view_all_tickets', 'view_confidential']],
        ];

        foreach ($variants as $config) {
            config($config);
            foreach (User::all() as $user) {
                $visible = $this->visibleIds($user);
                foreach (MaintenanceRequest::all() as $ticket) {
                    $this->assertSame(
                        in_array($ticket->id, $visible, true),
                        Gate::forUser($user)->allows('view', $ticket),
                        "{$user->email} / {$ticket->ticket_no}"
                    );
                }
            }
        }
    }

    public function test_requesters_only_ever_see_their_own_tickets_in_the_sample_data(): void
    {
        $this->seed();
        $requester = $this->user('requester');

        $this->assertGreaterThan(0, MaintenanceRequest::visibleTo($requester)->count());
        $this->assertSame(0, MaintenanceRequest::visibleTo($requester)->where('requester_id', '!=', $requester->id)->count());
    }

    // ---- board, dashboards, notifications don't leak --------------------------------------------

    public function test_the_board_hides_tickets_the_user_may_not_see_and_refuses_actions_on_them(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester'), [
            'category_id' => $this->category()->id, 'department_id' => $this->user('requester')->department_id,
            'location' => 'SECRET-ROOM', 'description' => 'Hidden problem', 'is_confidential' => true,
        ]);
        TicketWorkflow::authorize($ticket, $this->user('president'));
        $staff = $this->user('maintenance');

        // default matrix: maintenance may see confidential tickets
        $this->actingAs($staff)->get(route('maintenance.board'))->assertOk()->assertSee('SECRET-ROOM');

        config(['permissions.maintenance_admin' => ['view_all_tickets', 'work']]);

        $this->actingAs($staff)->get(route('maintenance.board'))->assertOk()->assertDontSee('SECRET-ROOM');
        $this->actingAs($staff)->get(route('maintenance.board.cards'))->assertOk()->assertDontSee('SECRET-ROOM');
        $this->actingAs($staff)->post(route('maintenance.accept', $ticket))->assertNotFound();
        $this->assertSame(\App\Enums\TicketStatus::Approved, $ticket->fresh()->status);
    }

    public function test_the_board_actions_need_the_work_capability(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester'), [
            'category_id' => $this->category()->id, 'department_id' => $this->user('requester')->department_id,
            'location' => 'Library', 'description' => 'Door',
        ]);
        TicketWorkflow::authorize($ticket, $this->user('president'));

        config(['permissions.maintenance_admin' => ['view_all_tickets']]);   // "work" removed

        $this->actingAs($this->user('maintenance'))->post(route('maintenance.accept', $ticket))->assertForbidden();
    }

    public function test_dashboard_counts_only_include_tickets_the_user_may_see(): void
    {
        $this->seedBase();
        $this->make($this->user('requester'));
        $this->make($this->user('requester2'), ['is_confidential' => true]);

        $this->actingAs($this->user('finance'))->get(route('finance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['Pending tickets']['count'] === 1);

        $this->actingAs($this->user('president'))->get(route('president.dashboard'))->assertOk();

        $this->actingAs($this->user('requester'))->get(route('requester.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['My tickets']['count'] === 1
                && str_contains($cards['My tickets']['url'], route('tickets.index')));
    }

    public function test_alerts_for_a_ticket_are_not_sent_to_people_who_may_not_see_it(): void
    {
        $this->seedBase();
        $ticket = TicketWorkflow::open($this->user('requester'), [
            'category_id' => $this->category()->id, 'department_id' => $this->user('requester')->department_id,
            'location' => 'Library', 'description' => 'Hidden', 'is_confidential' => true,
        ]);

        config(['permissions.maintenance_admin' => ['view_all_tickets', 'work']]);   // no view_confidential
        FormNotifier::submitted($ticket);
        $this->assertSame(0, $this->user('maintenance')->notifications()->count());

        config(['permissions.maintenance_admin' => ['view_all_tickets', 'view_confidential', 'work']]);
        FormNotifier::submitted($ticket);
        $this->assertSame(1, $this->user('maintenance')->notifications()->count());
    }

    // ---- emergency flag rule --------------------------------------------------------------------

    public function test_a_requester_cannot_create_an_emergency_ticket_a_note_is_recorded_instead(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'category_id' => $this->category()->id,
            'location' => 'Comfort Room',
            'job_description' => 'Pipe burst',
            'reason' => 'Water flooding the hall.',
            'priority' => 'emergency',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ])->assertRedirect(route('dashboard'));

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertFalse($ticket->is_emergency);
        $this->assertNull($ticket->emergency_flagged_by);
        $this->assertSame('first_come_first_served', $ticket->priority);
        $this->assertDatabaseHas('request_status_logs', [
            'request_id' => $ticket->id,
            'action' => 'note',
            'note' => 'Requester asked for emergency review: Water flooding the hall.',
        ]);
        $this->assertDatabaseMissing('request_status_logs', ['request_id' => $ticket->id, 'action' => 'emergency_flag']);
    }

    public function test_the_advance_notice_rule_is_not_skipped_by_someone_who_cannot_flag(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'category_id' => $this->category()->id,
            'location' => 'Comfort Room',
            'job_description' => 'Pipe burst',
            'reason' => 'Water flooding the hall.',
            'priority' => 'emergency',
        ])->assertSessionHasErrors('needed_by');

        $this->assertDatabaseCount('requests', 0);
    }

    public function test_a_user_who_may_flag_still_skips_the_advance_notice_rule(): void
    {
        $this->seedBase();
        $president = $this->user('president');

        $this->actingAs($president)->post(route('repairs.store'), [
            'department_id' => $president->department_id,
            'category_id' => $this->category()->id,
            'location' => 'Main Gate',
            'job_description' => 'Exposed wire',
            'reason' => 'Live wire near students.',
            'priority' => 'emergency',
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue(MaintenanceRequest::firstOrFail()->is_emergency);
    }

    public function test_only_people_who_may_flag_see_the_real_emergency_option(): void
    {
        $this->seedBase();

        $this->actingAs($this->user('requester'))->get(route('repairs.create'))->assertOk()->assertSee('Ask for');
        $this->actingAs($this->user('maintenance'))->get(route('repairs.create'))->assertOk()->assertDontSee('Ask for');
    }

    public function test_the_repair_form_follows_the_filing_roles_setting(): void
    {
        $this->seedBase();
        config(['school.filing_roles' => ['requester']]);

        $this->actingAs($this->user('requester'))->get(route('repairs.create'))->assertOk();
        $this->actingAs($this->user('finance'))->get(route('repairs.create'))->assertForbidden();
    }

    // ---- ticket list: order ---------------------------------------------------------------------

    public function test_list_order_is_emergency_first_then_original_submission_time(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance');
        $req = $this->user('requester');

        $a = $this->make($req, ['submitted_at' => now()->subHours(3)]);
        $b = $this->make($req, ['submitted_at' => now()->subHours(2)]);
        $c = $this->make($req, ['submitted_at' => now()->subHour()]);

        $this->assertEquals([$a->id, $b->id, $c->id], $this->listIds($staff));

        TicketWorkflow::flagEmergency($c, $staff, 'Water leak');
        $this->assertEquals([$c->id, $a->id, $b->id], $this->listIds($staff));

        TicketWorkflow::clearEmergency($c, $staff);
        $this->assertEquals([$a->id, $b->id, $c->id], $this->listIds($staff), 'clearing the flag restores the original place');
    }

    // ---- ticket list: search and filters --------------------------------------------------------

    /** @return array{a: MaintenanceRequest, b: MaintenanceRequest, c: MaintenanceRequest} */
    private function filterData(): array
    {
        $this->seedBase();
        $staff2 = $this->user('maintenance2');

        $a = $this->make($this->user('requester'), [
            'category_id' => $this->category(0)->id, 'location' => 'Gym Hall', 'description' => 'Broken door lock',
            'reason' => 'Students got locked out', 'submitted_at' => '2026-01-10 09:00:00',
        ]);
        $b = $this->make($this->user('requester2'), [
            'category_id' => $this->category(1)->id, 'location' => 'Canteen', 'description' => 'Leaking faucet',
            'reason' => 'Water waste', 'assigned_to' => $staff2->id, 'status' => 'assigned', 'is_emergency' => true,
            'emergency_reason' => 'Flooding', 'submitted_at' => '2026-02-10 09:00:00',
        ]);
        $c = $this->make($this->user('requester3'), [
            'category_id' => $this->category(0)->id, 'location' => 'Library', 'description' => 'Light flicker',
            'reason' => 'Eye strain', 'submitted_at' => '2026-03-10 09:00:00',
        ]);
        TicketWorkflow::authorize($c, $this->user('president'));   // c -> Approved

        return compact('a', 'b', 'c');
    }

    public function test_search_finds_by_ticket_number_keyword_location_reason_and_requester(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->filterData();
        $staff = $this->user('maintenance');

        $this->assertEquals([$b->id], $this->listIds($staff, ['q' => substr($b->ticket_no, -5)]), 'partial ticket number');
        $this->assertEquals([$c->id], $this->listIds($staff, ['q' => $c->ticket_no]), 'full ticket number');
        $this->assertEquals([$b->id], $this->listIds($staff, ['q' => 'faucet']), 'description');
        $this->assertEquals([$c->id], $this->listIds($staff, ['q' => 'Library']), 'location');
        $this->assertEquals([$a->id], $this->listIds($staff, ['q' => 'locked out']), 'reason');
        $this->assertEquals([$b->id], $this->listIds($staff, ['q' => 'High School']), 'requester name');
        $this->assertSame([], $this->listIds($staff, ['q' => 'nothing-matches-this']));
    }

    public function test_every_filter_returns_the_right_rows(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->filterData();
        $staff = $this->user('maintenance');

        // unfiltered: the emergency first, then oldest first
        $this->assertEquals([$b->id, $a->id, $c->id], $this->listIds($staff));

        $this->assertEquals([$c->id], $this->listIds($staff, ['status' => ['approved']]), 'one status');
        $this->assertEquals([$a->id, $c->id], $this->listIds($staff, ['status' => ['submitted', 'approved']]), 'several statuses');
        $this->assertEquals([$b->id], $this->listIds($staff, ['category_id' => $this->category(1)->id]), 'category');
        $this->assertEquals([$b->id], $this->listIds($staff, ['assigned_to' => $this->user('maintenance2')->id]), 'assignee');
        $this->assertEquals([$a->id, $c->id], $this->listIds($staff, ['assigned_to' => 'unassigned']), 'unassigned');
        $this->assertEquals([$b->id], $this->listIds($staff, ['department_id' => $this->user('requester2')->department_id]), 'department');
        $this->assertEquals([$b->id], $this->listIds($staff, ['emergency' => 1]), 'emergency only');

        $this->assertEquals([$b->id], $this->listIds($staff, ['from' => '2026-02-01', 'to' => '2026-02-28']), 'date range');
        $this->assertEquals([$b->id, $c->id], $this->listIds($staff, ['from' => '2026-02-01']), 'from only');
        $this->assertEquals([$a->id], $this->listIds($staff, ['to' => '2026-01-31']), 'to only');
        $this->assertEquals([$a->id], $this->listIds($staff, ['from' => '2026-01-10', 'to' => '2026-01-10']), 'range includes both end days');

        // filters combine
        $this->assertEquals([$c->id], $this->listIds($staff, ['category_id' => $this->category(0)->id, 'status' => ['approved']]));

        // "Only tickets I filed"
        $this->assertEquals([$a->id], $this->listIds($this->user('requester'), ['mine' => 1]));
    }

    public function test_filters_never_widen_what_the_user_may_see(): void
    {
        ['a' => $a, 'b' => $b] = $this->filterData();
        $secret = $this->make($this->user('requester2'), ['is_confidential' => true, 'location' => 'SECRET-ROOM']);

        // finance may not see confidential tickets, even when searching for them by name or number
        $this->assertSame([], $this->listIds($this->user('finance'), ['q' => 'SECRET-ROOM']));
        $this->assertSame([], $this->listIds($this->user('finance'), ['q' => $secret->ticket_no]));
        $this->assertNotContains($secret->id, $this->listIds($this->user('finance')));

        // a requester searching everyone's tickets still only finds their own
        $this->assertSame([], $this->listIds($this->user('requester'), ['q' => 'faucet']));
        $this->assertEquals([$a->id], $this->listIds($this->user('requester')));
    }

    public function test_pagination_has_15_per_page_and_keeps_the_filters(): void
    {
        $this->seedBase();
        $req = $this->user('requester');
        for ($i = 0; $i < 17; $i++) {
            $this->make($req, ['location' => 'Gym', 'submitted_at' => now()->subMinutes(100 - $i)]);
        }
        $this->make($req, ['location' => 'Elsewhere']);

        $staff = $this->user('maintenance');
        $query = ['q' => 'Gym', 'status' => ['submitted']];

        $page1 = $this->actingAs($staff)->get(route('tickets.index', $query))->assertOk()->viewData('tickets');
        $this->assertSame(15, $page1->count());
        $this->assertSame(17, $page1->total());
        $this->assertSame(2, $page1->lastPage());

        $next = urldecode($page1->nextPageUrl());
        $this->assertStringContainsString('q=Gym', $next);
        $this->assertStringContainsString('status[0]=submitted', $next);

        $page2 = $this->actingAs($staff)->get(route('tickets.index', $query + ['page' => 2]))->viewData('tickets');
        $this->assertSame(2, $page2->count());
    }

    public function test_empty_state_and_clear_filters_link(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance');

        $this->actingAs($staff)->get(route('tickets.index'))->assertOk()
            ->assertSee('No tickets found.')->assertDontSee('Clear filters');

        $this->actingAs($staff)->get(route('tickets.index', ['q' => 'zzz']))->assertOk()
            ->assertSee('No tickets found.')->assertSee('Clear filters');
    }

    public function test_the_list_needs_login(): void
    {
        $this->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->get(route('tickets.show', 1))->assertRedirect(route('login'));
    }

    // ---- ticket detail --------------------------------------------------------------------------

    public function test_the_detail_page_shows_the_full_history_in_order(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance');
        $ticket = TicketWorkflow::open($this->user('requester'), [
            'category_id' => $this->category()->id, 'department_id' => $this->user('requester')->department_id,
            'location' => 'Grade 5 Room 204', 'description' => 'Leaking faucet',
        ]);
        TicketWorkflow::authorize($ticket, $this->user('president'));
        TicketWorkflow::startWork($ticket, $staff);
        TicketWorkflow::markWorkCompleted($ticket, $staff);

        $response = $this->actingAs($staff)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee($ticket->ticket_no)->assertSee('Grade 5 Room 204')->assertSee('Test Requester')
            ->assertSee('Actions')
            ->assertSeeInOrder(['Ticket submitted.', 'Authorized.', 'Assigned to Test Maintenance Admin.', 'Work started.', 'Work completed.']);

        $this->assertSame(
            ['status_change', 'authorization', 'assignment', 'status_change', 'status_change'],
            $response->viewData('ticket')->statusLogs->pluck('action')->all()
        );
    }

    public function test_the_detail_page_shows_emergency_confidential_items_and_attachment_names(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance');
        $ticket = $this->make($this->user('requester'), [
            'is_confidential' => true, 'is_emergency' => true, 'emergency_reason' => 'Live wire', 'emergency_flagged_by' => $staff->id,
        ]);
        $ticket->items()->create(['item_name' => 'LED bulb', 'quantity' => 3, 'unit' => 'pc']);
        $ticket->attachments()->create(['uploaded_by' => $staff->id, 'path' => 'x/y.jpg', 'original_name' => 'wire-photo.jpg', 'mime_type' => 'image/jpeg']);

        $this->actingAs($staff)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Live wire')->assertSee('Confidential')->assertSee('LED bulb')->assertSee('wire-photo.jpg');
    }

    // ---- registration flag ----------------------------------------------------------------------

    public function test_public_registration_can_be_turned_off(): void
    {
        $this->get('/register')->assertOk();

        config(['school.allow_public_registration' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_every_seeded_role_lands_on_a_working_dashboard_and_can_open_the_ticket_list(): void
    {
        $this->seed();

        foreach (User::all() as $user) {
            $status = $this->actingAs($user)->get(route($user->dashboardRoute()))->getStatusCode();
            $this->assertContains($status, [200, 302], "{$user->email} dashboard returned {$status}");

            $this->actingAs($user)->get(route('tickets.index'))->assertOk()->assertSee('Tickets');
        }
    }
}
