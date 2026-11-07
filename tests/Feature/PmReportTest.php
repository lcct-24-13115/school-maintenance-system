<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\PmCompliance;
use App\Services\PmTicketGenerator;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PM E3: the PM compliance report (page, CSV, PDF), its numbers, its permissions, and the dashboard cards.
 * "Today" is 2026-11-08.
 */
class PmReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);

        Carbon::setTestNow('2026-11-08 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    /** A PM ticket due on $due, then put in the given state. */
    private function pmTicket(string $title, string $due, array $state = [], string $facility = 'Conference Room'): MaintenanceRequest
    {
        $schedule = PmSchedule::create([
            'title' => $title,
            'facility_id' => Facility::where('name', $facility)->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->user('maintenance')->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => $due,
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->user('maintenance')->id,
        ]);

        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08', true);
        $this->assertNotNull($ticket, "the ticket for {$title} was not made");

        $this->setState($ticket, $state);

        return $ticket->fresh();
    }

    /** Straight to the table: the model refuses a jump such as Submitted -> Closed, and a test needs exactly that. */
    private function setState(MaintenanceRequest $ticket, array $state): void
    {
        if ($state === []) {
            return;
        }

        $raw = array_map(fn ($v) => match (true) {
            $v instanceof \BackedEnum => $v->value,
            $v instanceof Carbon => $v->format('Y-m-d H:i:s'),
            default => $v,
        }, $state);

        DB::table('requests')->where('id', $ticket->id)->update($raw);
    }

    /** Conference Room: A on time, B late (3 days), C overdue (19 days), D not due yet, E cancelled. Chapel: F on time. */
    private function data(): array
    {
        return [
            'A' => $this->pmTicket('A on time', '2026-10-10', ['status' => TicketStatus::Closed, 'pm_completed_date' => '2026-10-09']),
            'B' => $this->pmTicket('B late', '2026-10-12', ['status' => TicketStatus::WorkCompleted, 'pm_completed_date' => '2026-10-15']),
            'C' => $this->pmTicket('C overdue', '2026-10-20'),
            'D' => $this->pmTicket('D not due yet', '2026-11-12'),
            'E' => $this->pmTicket('E cancelled', '2026-10-25', ['status' => TicketStatus::Cancelled]),
            'F' => $this->pmTicket('F chapel on time', '2026-09-01', ['status' => TicketStatus::Closed, 'pm_completed_date' => '2026-09-01'], 'Chapel'),
        ];
    }

    public function test_every_ticket_lands_in_one_bucket_and_the_rate_is_counted_from_the_right_ones(): void
    {
        $this->data();

        $s = (new PmCompliance($this->user('maintenance')))->analyse()['summary'];

        $this->assertSame(2, $s['on_time']);
        $this->assertSame(1, $s['late']);
        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['open']);
        $this->assertSame(1, $s['cancelled']);
        $this->assertSame(5, $s['counted']);        // cancelled is left out
        $this->assertEquals(50.0, $s['rate']);       // 2 / (2 + 1 + 1): not-due-yet and cancelled are not in the base
        $this->assertEquals(3.0, $s['avg_days_late']);
        $this->assertSame(1, $s['due_soon']);        // D is due Nov 12
    }

    public function test_days_late_days_overdue_and_the_facility_and_month_tables(): void
    {
        $this->data();

        $r = (new PmCompliance($this->user('maintenance')))->analyse();

        $this->assertCount(1, $r['overdue']);
        $this->assertSame('C overdue', $r['overdue'][0]['schedule']);
        $this->assertSame(19, $r['overdue'][0]['days']);

        $byFacility = collect($r['by_facility'])->keyBy('label');
        $this->assertEquals(100.0, $byFacility['Chapel']['rate']);
        $this->assertSame(1, $byFacility['Chapel']['on_time']);
        $this->assertEquals(33.3, $byFacility['Conference Room']['rate']);   // 1 / (1 + 1 + 1)
        $this->assertSame(1, $byFacility['Conference Room']['cancelled']);

        $months = collect($r['by_month'])->pluck('key')->all();
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $months);
    }

    public function test_the_done_day_falls_back_to_the_day_the_work_was_completed(): void
    {
        $this->pmTicket('G no pm form', '2026-10-10', ['status' => TicketStatus::WorkCompleted, 'completed_at' => Carbon::parse('2026-10-12 09:00:00')]);

        $r = (new PmCompliance($this->user('maintenance')))->analyse();

        $this->assertSame(1, $r['summary']['late']);
        $this->assertSame(2, $r['rows'][0]['days']);
        $this->assertSame('2026-10-12', $r['rows'][0]['done']);
    }

    public function test_the_filters_narrow_the_numbers(): void
    {
        $this->data();
        $admin = $this->user('maintenance');

        $nov = (new PmCompliance($admin, '2026-11-01', '2026-11-30'))->analyse()['summary'];
        $this->assertSame(1, $nov['counted']);
        $this->assertSame(1, $nov['open']);
        $this->assertNull($nov['rate']);                       // nothing done or overdue yet

        $chapel = (new PmCompliance($admin, null, null, (int) Facility::where('name', 'Chapel')->value('id')))->analyse()['summary'];
        $this->assertSame(1, $chapel['on_time']);
        $this->assertSame(1, $chapel['counted']);
        $this->assertEquals(100.0, $chapel['rate']);
    }

    public function test_a_confidential_pm_ticket_is_not_counted_for_a_role_that_may_not_see_it(): void
    {
        $t = $this->data();
        $this->setState($t['A'], ['is_confidential' => true]);

        $this->assertSame(2, (new PmCompliance($this->user('maintenance')))->analyse()['summary']['on_time']);
        $this->assertSame(1, (new PmCompliance($this->user('finance')))->analyse()['summary']['on_time']);
    }

    public function test_the_page_shows_the_numbers_to_the_maintenance_admin(): void
    {
        $t = $this->data();

        $this->actingAs($this->user('maintenance'))->get(route('pm-reports.index'))
            ->assertOk()
            ->assertSee('PM compliance report')
            ->assertSee('50.0%')
            ->assertSee($t['C']->ticket_no)
            ->assertSee('Conference Room')
            ->assertSee('Chapel')
            ->assertSee(route('pm-reports.export'), false)
            ->assertSee(route('pm-reports.pdf'), false);
    }

    public function test_the_page_validates_the_dates(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->get(route('pm-reports.index', ['from' => '2026-11-10', 'to' => '2026-11-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_empty_state(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('pm-reports.index'))
            ->assertOk()->assertSee('No PM tickets are due in this range.');
    }

    public function test_who_may_open_the_page_the_csv_and_the_pdf(): void
    {
        $this->data();

        foreach (['maintenance', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who));
            $this->get(route('pm-reports.index'))->assertOk();
            $this->get(route('pm-reports.export'))->assertOk();
            $this->get(route('pm-reports.pdf'))->assertOk();
        }

        foreach (['custodian', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->actingAs($this->user($who));
            $this->get(route('pm-reports.index'))->assertForbidden();
            $this->get(route('pm-reports.export'))->assertForbidden();
            $this->get(route('pm-reports.pdf'))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('pm-reports.index'))->assertRedirect(route('login'));
        $this->get(route('pm-reports.export'))->assertRedirect(route('login'));
        $this->get(route('pm-reports.pdf'))->assertRedirect(route('login'));
    }

    public function test_the_csv_has_one_line_per_ticket_and_is_written_to_the_audit_log(): void
    {
        $t = $this->data();

        $response = $this->actingAs($this->user('finance'))->get(route('pm-reports.export'));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Ticket no', $csv);
        $this->assertStringContainsString('Days late / overdue', $csv);
        foreach ($t as $ticket) {
            $this->assertStringContainsString($ticket->ticket_no, $csv);
        }
        $this->assertStringContainsString('Done late', $csv);
        $this->assertStringContainsString('Overdue', $csv);
        $this->assertSame(1 + 6, count(array_filter(preg_split('/\r?\n/', $csv))));   // header + 6 tickets

        $this->assertSame(1, AuditLog::where('description', 'like', 'Exported PM compliance report (CSV)%')->count());
    }

    public function test_the_csv_respects_the_filters(): void
    {
        $t = $this->data();

        $csv = $this->actingAs($this->user('maintenance'))
            ->get(route('pm-reports.export', ['from' => '2026-11-01']))->streamedContent();

        $this->assertStringContainsString($t['D']->ticket_no, $csv);
        $this->assertStringNotContainsString($t['A']->ticket_no, $csv);
    }

    public function test_the_pdf_is_a_pdf_and_is_written_to_the_audit_log(): void
    {
        $this->data();

        $this->actingAs($this->user('president'))->get(route('pm-reports.pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(1, AuditLog::where('description', 'like', 'Exported PM compliance report (PDF)%')->count());
    }

    public function test_the_dashboard_cards_for_finance_and_the_president(): void
    {
        $this->data();

        $this->actingAs($this->user('finance'))->get(route('finance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['PM overdue']['count'] === 1
                && $cards['PM due in 7 days']['count'] === 1
                && $cards['PM on-time rate (90 days)']['display'] === '50.0%'
                && str_contains($cards['PM overdue']['url'], route('pm-reports.index')));

        $this->actingAs($this->user('president'))->get(route('president.dashboard'))->assertOk()
            ->assertSee('PM overdue')
            ->assertSee('50.0%')
            ->assertSee(route('pm-reports.index'), false);
    }

    public function test_the_rate_card_shows_a_dash_when_there_is_nothing_to_count(): void
    {
        $this->actingAs($this->user('finance'))->get(route('finance.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards['PM on-time rate (90 days)']['display'] === '—'
                && $cards['PM overdue']['count'] === 0);
    }

    public function test_the_custodian_dashboard_has_no_pm_cards(): void
    {
        $this->data();

        $this->actingAs($this->user('custodian'))->get(route('custodian.dashboard'))->assertOk()
            ->assertViewHas('cards', fn ($cards) => ! array_key_exists('PM overdue', $cards))
            ->assertDontSee(route('pm-reports.index'), false);
    }

    public function test_the_menu_link_follows_the_permission(): void
    {
        foreach (['maintenance', 'finance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.index'))
                ->assertOk()->assertSee(route('pm-reports.index'), false);
        }

        foreach (['custodian', 'requester'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.index'))
                ->assertOk()->assertDontSee(route('pm-reports.index'), false);
        }
    }
}
