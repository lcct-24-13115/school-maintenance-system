<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\TicketReply;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TicketSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 16: the Reports upgrade. First-response time, resolution rate, backlog, active-vs-all categories, daily
 * volume, workload with overdue, and the "Needs attention" list with quick-assign.
 *
 * The clock is frozen at 2025-06-15 12:00 so "overdue" and "stale" are exact. The last test uses the real clock and
 * the real TicketSeeder, and checks every number against separate raw SQL.
 */
class ReportOverviewTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2025-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers --------------------------------------------------------------------------------

    /** user('requester') => requester@lcctanauan.edu.ph */
    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    /** A ticket straight through the model, so dates and statuses are exact. */
    private function make(array $overrides = []): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return MaintenanceRequest::create($overrides + [
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Broken window',
            'submitted_at' => Carbon::parse('2025-06-10 08:00:00'),
        ]);
    }

    /** A Closed ticket (a closed ticket needs a recorded authorization). */
    private function closed(array $overrides = []): MaintenanceRequest
    {
        return $this->make($overrides + [
            'status' => TicketStatus::Closed,
            'president_id' => $this->user('president')->id,
            'president_approved_at' => Carbon::parse('2025-06-02 08:00:00'),
            'closed_at' => Carbon::parse('2025-06-05 08:00:00'),
        ]);
    }

    private function log(MaintenanceRequest $ticket, ?User $by, string $at): void
    {
        RequestStatusLog::forceCreate([
            'request_id' => $ticket->id,
            'user_id' => $by?->id,
            'action' => RequestStatusLog::ACTION_STATUS,
            'to_status' => 'submitted',
            'note' => 'x',
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    private function reply(MaintenanceRequest $ticket, User $by, string $at, bool $internal = false): void
    {
        TicketReply::forceCreate([
            'request_id' => $ticket->id,
            'user_id' => $by->id,
            'body' => 'hello',
            'is_internal' => $internal,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    /** Back-date the ticket's own updated_at (Eloquent would always set it to "now"). */
    private function touchedAt(MaintenanceRequest $ticket, string $at): void
    {
        DB::table('requests')->where('id', $ticket->id)->update(['updated_at' => Carbon::parse($at)]);
    }

    private function page(string $who, array $query = [])
    {
        return $this->actingAs($this->user($who))->get(route('reports.index', $query))->assertOk();
    }

    private function metrics(string $who, array $query = []): array
    {
        return $this->page($who, $query)->viewData('metrics');
    }

    /** @return list<int> ticket ids in the "Needs attention" list, in the order shown */
    private function attentionIds(string $who): array
    {
        return array_map(fn ($item) => $item['ticket']->id, $this->page($who)->viewData('attention')['items']);
    }

    // ---- first response -------------------------------------------------------------------------

    public function test_first_response_is_the_first_action_by_someone_other_than_the_requester(): void
    {
        $requester = $this->user('requester');
        $staff = $this->user('maintenance');

        $a = $this->make();
        $b = $this->make();
        $c = $this->make();
        $this->make();   // d: nobody did anything

        // A: the requester's own line (ignored), the staff 2 h after submitting (counts), a later line (ignored)
        $this->log($a, $requester, '2025-06-10 08:00:00');
        $this->log($a, $staff, '2025-06-10 10:00:00');
        $this->log($a, $staff, '2025-06-11 10:00:00');

        // B: the requester's own message (ignored), a staff INTERNAL note (ignored), a staff message after 6 h (counts)
        $this->reply($b, $requester, '2025-06-10 09:00:00');
        $this->reply($b, $staff, '2025-06-10 09:30:00', internal: true);
        $this->reply($b, $staff, '2025-06-10 14:00:00');

        // C: only an automatic line with no user (ignored): not answered
        $this->log($c, null, '2025-06-10 08:05:00');

        foreach (['maintenance', 'president', 'finance'] as $who) {   // everybody sees the same number
            $m = $this->metrics($who);

            $this->assertSame(4, $m['total'], $who);
            $this->assertEquals(4.0, $m['avg_first_response_hours'], $who);   // (2 + 6) / 2
            $this->assertSame(2, $m['first_response_count'], $who);
        }
    }

    public function test_first_response_is_empty_when_nobody_answered(): void
    {
        $this->make();

        $m = $this->metrics('maintenance');

        $this->assertNull($m['avg_first_response_hours']);
        $this->assertSame(0, $m['first_response_count']);
    }

    // ---- resolution rate and backlog -------------------------------------------------------------

    public function test_resolution_rate_leaves_out_cancelled_tickets(): void
    {
        $staff = $this->user('maintenance');

        $this->make(['status' => TicketStatus::WorkCompleted, 'completed_at' => Carbon::parse('2025-06-12 08:00:00')]);
        $this->closed();
        $this->make(['status' => TicketStatus::Cancelled]);
        $this->make(['status' => TicketStatus::Disapproved]);
        $this->make(['status' => TicketStatus::Submitted]);
        $this->make(['status' => TicketStatus::Assigned, 'assigned_to' => $staff->id]);
        $this->make(['status' => TicketStatus::WaitingForUser]);

        $m = $this->metrics('maintenance');

        $this->assertSame(7, $m['total']);
        $this->assertSame(1, $m['cancelled']);
        $this->assertSame(2, $m['resolved']);              // Work Completed + Closed
        $this->assertEquals(33.3, $m['resolution_rate']);  // 2 / (7 - 1)
        $this->assertSame(3, $m['backlog']);               // Submitted, Assigned, Waiting for User (Disapproved is not backlog)
    }

    public function test_resolution_rate_is_empty_when_only_cancelled_tickets_exist(): void
    {
        $this->make(['status' => TicketStatus::Cancelled]);
        $this->assertNull($this->metrics('maintenance')['resolution_rate']);

        $this->assertNull($this->metrics('maintenance', ['from' => '2030-01-01'])['resolution_rate']);   // no tickets at all
    }

    // ---- categories: active vs all ---------------------------------------------------------------

    public function test_categories_show_all_tickets_and_the_ones_still_open(): void
    {
        [$one, $two] = Category::query()->orderBy('id')->limit(2)->get()->all();
        $staff = $this->user('maintenance');

        $this->make(['category_id' => $one->id]);
        $this->closed(['category_id' => $one->id]);
        $this->make(['category_id' => $two->id]);
        $this->make(['category_id' => $two->id, 'status' => TicketStatus::InProgress, 'assigned_to' => $staff->id]);
        $this->make(['category_id' => $two->id, 'status' => TicketStatus::WorkCompleted]);

        $page = $this->page('maintenance');
        $rows = collect($page->viewData('metrics')['by_category'])->keyBy('label');

        $this->assertSame(['count' => 2, 'active' => 1], collect($rows[$one->name])->only(['count', 'active'])->all());
        $this->assertSame(['count' => 3, 'active' => 2], collect($rows[$two->name])->only(['count', 'active'])->all());

        $chart = $page->viewData('charts')['category'];   // the biggest category first
        $this->assertSame($two->name, $chart['labels'][0]);
        $this->assertSame([3, 2], [$chart['data'][0], $chart['active'][0]]);
    }

    // ---- daily volume ----------------------------------------------------------------------------

    public function test_daily_volume_covers_30_days_and_fills_empty_days(): void
    {
        $this->make(['submitted_at' => Carbon::parse('2025-06-15 08:00:00')]);
        $this->make(['submitted_at' => Carbon::parse('2025-06-15 09:30:00')]);
        $this->make(['submitted_at' => Carbon::parse('2025-06-13 10:00:00')]);
        $this->make(['submitted_at' => Carbon::parse('2025-05-01 10:00:00')]);   // before the 30-day window

        $daily = $this->metrics('maintenance')['daily'];

        $this->assertCount(30, $daily);
        $this->assertSame('May 17', $daily[0]['label']);
        $this->assertSame('Jun 15', $daily[29]['label']);
        $this->assertSame(2, $daily[29]['count']);
        $this->assertSame(0, $daily[28]['count']);   // Jun 14: no tickets, still listed
        $this->assertSame(1, $daily[27]['count']);   // Jun 13
        $this->assertSame(3, array_sum(array_column($daily, 'count')));

        $chart = $this->page('maintenance')->viewData('charts')['daily'];
        $this->assertCount(30, $chart['labels']);
        $this->assertSame($chart['data'], array_column($daily, 'count'));
    }

    public function test_daily_volume_follows_the_date_range(): void
    {
        $this->make(['submitted_at' => Carbon::parse('2025-06-15 08:00:00')]);
        $this->make(['submitted_at' => Carbon::parse('2025-05-01 10:00:00')]);

        // "from" inside the window shortens it
        $daily = $this->metrics('maintenance', ['from' => '2025-06-14'])['daily'];
        $this->assertSame(['Jun 14', 'Jun 15'], array_column($daily, 'label'));
        $this->assertSame([0, 1], array_column($daily, 'count'));

        // "to" moves the window back: 30 days ending on that day
        $daily = $this->metrics('maintenance', ['to' => '2025-05-01'])['daily'];
        $this->assertCount(30, $daily);
        $this->assertSame('May 1', $daily[29]['label']);
        $this->assertSame(1, array_sum(array_column($daily, 'count')));

        // a range that starts after today has no days
        $this->assertSame([], $this->metrics('maintenance', ['from' => '2025-07-01'])['daily']);
    }

    // ---- workload --------------------------------------------------------------------------------

    public function test_workload_counts_overdue_tickets_per_staff(): void
    {
        $one = $this->user('maintenance');
        $two = $this->user('maintenance2');

        // overdue: submitted 06-01, target 06-06
        $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::InProgress, 'assigned_to' => $one->id]);
        // fine: submitted yesterday
        $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::Assigned, 'assigned_to' => $one->id]);
        // late but paused (waiting for the requester): not overdue
        $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::WaitingForUser, 'assigned_to' => $two->id]);
        // late but finished: not overdue
        $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::WorkCompleted, 'assigned_to' => $two->id, 'completed_at' => Carbon::parse('2025-06-09 08:00:00')]);

        $rows = collect($this->metrics('maintenance')['workload'])->keyBy('name');

        $this->assertSame(['assigned' => 2, 'open' => 2, 'completed' => 0, 'overdue' => 1], collect($rows[$one->name])->only(['assigned', 'open', 'completed', 'overdue'])->all());
        $this->assertSame(['assigned' => 2, 'open' => 1, 'completed' => 1, 'overdue' => 0], collect($rows[$two->name])->only(['assigned', 'open', 'completed', 'overdue'])->all());
    }

    // ---- needs attention -------------------------------------------------------------------------

    /**
     * @return array<string, MaintenanceRequest>
     *   O  assigned, overdue                       U  EMERGENCY, approved, nobody assigned
     *   X  approved, nobody assigned AND overdue   S  in progress, nothing happened for 5 days
     *   C  CONFIDENTIAL, approved, nobody assigned F  fine (assigned, new)
     *   W  waiting for the requester (paused)      D  closed (finished)
     *   L  quiet ticket, but a history line yesterday   I  quiet ticket, but an INTERNAL note today
     */
    private function attentionSet(): array
    {
        $staff = $this->user('maintenance');
        $assigned = ['assigned_to' => $staff->id];

        $set = [
            'O' => $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::Assigned] + $assigned),
            'U' => $this->make(['submitted_at' => Carbon::parse('2025-06-15 08:00:00'), 'status' => TicketStatus::Approved, 'is_emergency' => true, 'emergency_reason' => 'Gas smell']),
            'X' => $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::Approved]),
            'S' => $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::InProgress] + $assigned),
            'C' => $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::Approved, 'is_confidential' => true]),
            'F' => $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::Assigned] + $assigned),
            'W' => $this->make(['submitted_at' => Carbon::parse('2025-06-01 08:00:00'), 'status' => TicketStatus::WaitingForUser] + $assigned),
            'D' => $this->closed(['submitted_at' => Carbon::parse('2025-06-01 08:00:00')] + $assigned),
            'L' => $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::InProgress] + $assigned),
            'I' => $this->make(['submitted_at' => Carbon::parse('2025-06-14 08:00:00'), 'status' => TicketStatus::InProgress] + $assigned),
        ];

        foreach (['S', 'W', 'L', 'I'] as $key) {
            $this->touchedAt($set[$key], $key === 'W' ? '2025-06-05 12:00:00' : '2025-06-10 12:00:00');
        }

        $this->log($set['L'], $staff, '2025-06-14 12:00:00');            // activity: L is not stale
        $this->reply($set['I'], $staff, '2025-06-15 09:00:00', true);    // an internal note counts, but only for people who may read it

        return $set;
    }

    private function ids(array $set, string $keys): array
    {
        return array_map(fn ($k) => $set[$k]->id, str_split($keys));
    }

    public function test_needs_attention_lists_overdue_unassigned_and_stale_tickets(): void
    {
        $set = $this->attentionSet();

        $attention = $this->page('maintenance')->viewData('attention');
        $byId = collect($attention['items'])->keyBy(fn ($i) => $i['ticket']->id);
        $keys = fn (string $k) => array_column($byId[$set[$k]->id]['reasons'], 'key');

        $this->assertSame(5, $attention['total']);   // O U X S C
        $this->assertEqualsCanonicalizing($this->ids($set, 'OUXSC'), $byId->keys()->all());

        $this->assertSame(['overdue'], $keys('O'));
        $this->assertSame(['unassigned'], $keys('U'));
        $this->assertSame(['overdue', 'unassigned'], $keys('X'));
        $this->assertSame(['stale'], $keys('S'));
        $this->assertSame(['unassigned'], $keys('C'));

        $this->assertSame('Overdue by 9 d', $byId[$set['O']->id]['reasons'][0]['label']);       // target 06-06 08:00, now 06-15 12:00
        $this->assertSame('No activity for 5 d', $byId[$set['S']->id]['reasons'][0]['label']);  // last change 06-10 12:00
        $this->assertTrue($byId[$set['U']->id]['unassigned']);
        $this->assertFalse($byId[$set['O']->id]['unassigned']);
    }

    public function test_needs_attention_puts_the_worst_first(): void
    {
        $set = $this->attentionSet();

        $ids = $this->attentionIds('maintenance');

        $this->assertSame($set['U']->id, $ids[0]);   // emergency first
        $this->assertSame($set['X']->id, $ids[1]);   // then the one with two reasons
    }

    public function test_needs_attention_ignores_the_date_range_and_shows_the_real_total(): void
    {
        $set = $this->attentionSet();

        $page = $this->page('maintenance', ['from' => '2030-01-01']);   // nothing was submitted then...

        $this->assertSame(0, $page->viewData('metrics')['total']);
        $this->assertSame(5, $page->viewData('attention')['total']);    // ...but the list is about NOW
        $this->assertEqualsCanonicalizing($this->ids($set, 'OUXSC'), $this->attentionIds('maintenance'));

        config(['school.reports.attention_limit' => 2]);
        $limited = $this->page('maintenance')->viewData('attention');
        $this->assertSame(5, $limited['total']);
        $this->assertCount(2, $limited['items']);
        $this->assertSame(array_slice($this->ids($set, 'UX'), 0, 2), $this->attentionIds('maintenance'));
    }

    public function test_the_stale_days_are_configurable(): void
    {
        $set = $this->attentionSet();

        config(['school.reports.stale_days' => 7]);   // S was quiet for 5 days only

        $this->assertNotContains($set['S']->id, $this->attentionIds('maintenance'));
    }

    public function test_confidential_tickets_never_reach_the_attention_list_of_people_who_may_not_see_them(): void
    {
        $set = $this->attentionSet();

        // maintenance and the president: C is there
        $this->assertContains($set['C']->id, $this->attentionIds('maintenance'));
        $this->assertContains($set['C']->id, $this->attentionIds('president'));

        // finance: no C in the list, the counts or the page
        $page = $this->page('finance');
        $this->assertNotContains($set['C']->id, $this->attentionIds('finance'));
        $this->assertSame(5, $page->viewData('attention')['total']);   // O U X S + I (finance cannot read the internal note on I)
        $this->assertSame(9, $page->viewData('metrics')['total']);     // 10 tickets, minus C
        $page->assertDontSee($set['C']->ticket_no);
    }

    public function test_an_internal_note_cannot_change_what_a_viewer_without_access_sees(): void
    {
        $set = $this->attentionSet();

        // I had an internal note today. Maintenance and the president may read it, so for them the ticket is active;
        // finance may not, so for finance it is as quiet as it looks (and the note's existence stays hidden).
        $this->assertNotContains($set['I']->id, $this->attentionIds('maintenance'));
        $this->assertNotContains($set['I']->id, $this->attentionIds('president'));
        $this->assertContains($set['I']->id, $this->attentionIds('finance'));
    }

    public function test_an_empty_attention_list_says_so(): void
    {
        $this->make(['status' => TicketStatus::Assigned, 'assigned_to' => $this->user('maintenance')->id, 'submitted_at' => Carbon::parse('2025-06-15 08:00:00')]);

        $this->page('maintenance')->assertSee('Nothing needs attention right now.');
    }

    // ---- quick assign ----------------------------------------------------------------------------

    public function test_only_people_who_may_assign_get_the_quick_assign_form(): void
    {
        $this->attentionSet();

        $this->page('maintenance')->assertSee('name="assignee_id"', false)->assertSee('Quick assign');
        $this->page('president')->assertDontSee('name="assignee_id"', false)->assertSee('Open ticket');
        $this->page('finance')->assertDontSee('name="assignee_id"', false);
    }

    public function test_quick_assign_assigns_and_returns_to_the_reports_with_the_same_dates(): void
    {
        $set = $this->attentionSet();
        $two = $this->user('maintenance2');

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.assign', $set['U']), ['assignee_id' => $two->id, 'return' => 'reports', 'from' => '2025-06-01', 'to' => '2025-06-30'])
            ->assertRedirect(route('reports.index', ['from' => '2025-06-01', 'to' => '2025-06-30']))
            ->assertSessionHas('status', 'Assigned.');

        $set['U']->refresh();
        $this->assertSame($two->id, $set['U']->assigned_to);
        $this->assertSame(TicketStatus::Assigned, $set['U']->status);

        // the ticket is no longer unassigned, so it leaves the list
        $this->assertNotContains($set['U']->id, $this->attentionIds('maintenance'));
    }

    public function test_quick_assign_ignores_bad_dates_and_other_return_values(): void
    {
        $set = $this->attentionSet();
        $two = $this->user('maintenance2');

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.assign', $set['X']), ['assignee_id' => $two->id, 'return' => 'reports', 'from' => 'javascript:alert(1)', 'to' => '2025-06-30'])
            ->assertRedirect(route('reports.index', ['to' => '2025-06-30']));

        // without return=reports it behaves as before: back to the ticket
        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.assign', $set['C']), ['assignee_id' => $two->id])
            ->assertRedirect(route('tickets.show', $set['C']).'#actions');
    }

    public function test_quick_assign_shows_the_workflow_error_on_the_reports_page(): void
    {
        $submitted = $this->make();   // Submitted: it must be approved before it can be assigned

        $this->actingAs($this->user('maintenance'))
            ->post(route('tickets.assign', $submitted), ['assignee_id' => $this->user('maintenance2')->id, 'return' => 'reports'])
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('error');

        $this->assertNull($submitted->refresh()->assigned_to);
    }

    public function test_quick_assign_is_checked_on_the_server(): void
    {
        $set = $this->attentionSet();
        $two = $this->user('maintenance2');

        // finance sees U but has no assign capability
        $this->actingAs($this->user('finance'))
            ->post(route('tickets.assign', $set['U']), ['assignee_id' => $two->id, 'return' => 'reports'])
            ->assertForbidden();

        // finance cannot even find the confidential ticket
        $this->actingAs($this->user('finance'))
            ->post(route('tickets.assign', $set['C']), ['assignee_id' => $two->id, 'return' => 'reports'])
            ->assertNotFound();

        $this->assertNull($set['U']->refresh()->assigned_to);
        $this->assertNull($set['C']->refresh()->assigned_to);
    }

    // ---- the page --------------------------------------------------------------------------------

    public function test_the_page_shows_the_new_numbers_and_charts(): void
    {
        $this->attentionSet();

        $page = $this->page('maintenance');

        foreach (['Backlog', 'Resolution rate', 'Avg. first response', 'Needs attention', 'Workload per assigned staff', 'Overdue'] as $text) {
            $page->assertSee($text);
        }
        $page->assertSee('id="chart-daily"', false);
        $this->assertArrayHasKey('daily', $page->viewData('charts'));
        $this->assertArrayHasKey('active', $page->viewData('charts')['category']);
    }

    // ---- every number against the real seeder, checked with separate SQL --------------------------

    public function test_the_numbers_match_the_seeded_data(): void
    {
        Carbon::setTestNow();   // the seeder spreads its tickets over the last 12 months from the real "now"
        $this->seed(TicketSeeder::class);
        Carbon::setTestNow(now());   // then stop the clock, so the page and this test use the very same "now"

        $this->assertSame(40, MaintenanceRequest::count());
        $confidential = MaintenanceRequest::where('is_confidential', true)->get(['id', 'ticket_no']);
        $this->assertGreaterThan(0, $confidential->count());

        $pending = ['submitted', 'pending_authorization', 'approved', 'assigned', 'in_progress', 'waiting_for_user', 'escalated', 'reopened'];
        $assignable = ['approved', 'assigned', 'in_progress', 'reopened'];
        $now = now();

        foreach (['maintenance', 'president', 'finance'] as $who) {
            $hidesConfidential = $who === 'finance';
            $visible = fn () => DB::table('requests')->whereNull('deleted_at')
                ->when($hidesConfidential, fn ($q) => $q->where('is_confidential', false));

            $total = $visible()->count();
            $this->assertSame($hidesConfidential ? 40 - $confidential->count() : 40, $total, $who);

            $backlog = $visible()->whereIn('status', $pending)->count();
            $resolved = $visible()->whereIn('status', ['work_completed', 'closed'])->count();
            $cancelled = $visible()->where('status', 'cancelled')->count();
            $overdueSql = fn ($q) => $q->whereIn('status', $pending)->where('status', '!=', 'waiting_for_user')
                ->whereNotNull('due_at')->where('due_at', '<', $now->toDateTimeString());

            $page = $this->page($who);
            $m = $page->viewData('metrics');

            // headline numbers
            $this->assertSame($total, $m['total'], $who);
            $this->assertSame($backlog, $m['backlog'], $who);
            $this->assertSame($resolved, $m['resolved'], $who);
            $this->assertSame($cancelled, $m['cancelled'], $who);
            $this->assertSame($overdueSql($visible())->count(), $m['overdue'], $who);
            $this->assertEquals(round($resolved / ($total - $cancelled) * 100, 1), $m['resolution_rate'], $who);

            // the breakdowns add up to the same totals
            $this->assertSame($total, array_sum(array_column($m['by_status'], 'count')), $who);
            $this->assertSame($total, array_sum(array_column($m['by_category'], 'count')), $who);
            $this->assertSame($backlog, array_sum(array_column($m['by_category'], 'active')), $who);
            $this->assertSame($total, array_sum(array_column($m['by_department'], 'count')), $who);
            $this->assertSame($total, array_sum(array_column($m['monthly'], 'count')), $who);

            // daily volume = tickets of the last 30 days
            $windowStart = $now->copy()->subDays(29)->startOfDay()->toDateTimeString();
            $this->assertCount(30, $m['daily'], $who);
            $this->assertSame($visible()->where('submitted_at', '>=', $windowStart)->count(), array_sum(array_column($m['daily'], 'count')), $who);

            // workload
            $assigned = $visible()->whereNotNull('assigned_to');
            $this->assertSame((clone $assigned)->count(), array_sum(array_column($m['workload'], 'assigned')), $who);
            $this->assertSame((clone $assigned)->whereIn('status', $pending)->count(), array_sum(array_column($m['workload'], 'open')), $who);
            $this->assertSame($overdueSql(clone $assigned)->count(), array_sum(array_column($m['workload'], 'overdue')), $who);

            // first response, from raw SQL: the first history line by anybody but the requester
            $first = DB::table('request_status_logs as l')->join('requests as r', 'r.id', '=', 'l.request_id')
                ->whereNull('r.deleted_at')->whereNotNull('l.user_id')->whereColumn('l.user_id', '<>', 'r.requester_id')
                ->when($hidesConfidential, fn ($q) => $q->where('r.is_confidential', false))
                ->groupBy('l.request_id', 'r.submitted_at')
                ->selectRaw('l.request_id, r.submitted_at, MIN(l.created_at) as first_at')->get();
            $seconds = $first->sum(fn ($r) => max(0, Carbon::parse($r->first_at)->getTimestamp() - Carbon::parse($r->submitted_at)->getTimestamp()));

            $this->assertSame($first->count(), $m['first_response_count'], $who);
            $this->assertEquals($first->isEmpty() ? null : round($seconds / $first->count() / 3600, 1), $m['avg_first_response_hours'], $who);

            // needs attention: the expected total, worked out one ticket at a time
            $cutoff = $now->copy()->subDays(3);
            $expected = 0;
            foreach ($visible()->whereIn('status', $pending)->get() as $row) {
                $overdue = $row->status !== 'waiting_for_user' && $row->due_at !== null && Carbon::parse($row->due_at)->lt($now);
                $unassigned = $row->assigned_to === null && in_array($row->status, $assignable, true);
                $lastLog = DB::table('request_status_logs')->where('request_id', $row->id)->max('created_at');
                $lastActivity = max(Carbon::parse($row->updated_at), $lastLog ? Carbon::parse($lastLog) : Carbon::parse($row->updated_at));
                $stale = $row->status !== 'waiting_for_user' && $lastActivity->lt($cutoff);

                $expected += ($overdue || $unassigned || $stale) ? 1 : 0;
            }
            $attention = $page->viewData('attention');
            $this->assertSame($expected, $attention['total'], $who);

            foreach ($attention['items'] as $item) {
                $this->assertContains($item['ticket']->status->value, $pending, $who);
                $this->assertNotEmpty($item['reasons'], $who);
            }

            // confidential tickets: not in the list, not on the page, for the role that may not see them
            if ($hidesConfidential) {
                $listed = array_map(fn ($i) => $i['ticket']->id, $attention['items']);
                foreach ($confidential as $ticket) {
                    $this->assertNotContains($ticket->id, $listed);
                    $page->assertDontSee($ticket->ticket_no);
                }
            }
        }
    }
}
