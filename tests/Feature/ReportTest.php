<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Csv;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Merge Phase M4: /reports (view_analytics), the CSV export (export_reports), ticket visibility,
 * CSV safety and the audit trail of exports.
 */
class ReportTest extends TestCase
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
            'category_id' => Category::query()->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Library',
            'description' => 'Broken window',
            'submitted_at' => Carbon::parse('2025-01-10 08:00:00'),
        ]);
    }

    /**
     * The data set for the numbers (all filed by the requester):
     *   A  Jan 10  Work Completed  approved after 4 h, completed after 30 h, staff "maintenance"
     *   B  Mar 5   Work Completed  approved after 8 h, completed after 50 h, staff "maintenance2"
     *   C  Mar 6   Submitted, EMERGENCY, past its target date  -> overdue
     *   D  Mar 7   Submitted, CONFIDENTIAL, past its target date -> overdue
     */
    private function dataSet(): array
    {
        $president = $this->user('president');
        $done = function (string $submitted, int $approveH, int $completeH, string $staff) use ($president) {
            $at = Carbon::parse($submitted);

            return $this->make([
                'submitted_at' => $at,
                'status' => TicketStatus::WorkCompleted,
                'president_id' => $president->id,
                'president_approved_at' => $at->copy()->addHours($approveH),
                'completed_at' => $at->copy()->addHours($completeH),
                'assigned_to' => $this->user($staff)->id,
            ]);
        };

        return [
            'A' => $done('2025-01-10 08:00:00', 4, 30, 'maintenance'),
            'B' => $done('2025-03-05 08:00:00', 8, 50, 'maintenance2'),
            'C' => $this->make(['submitted_at' => Carbon::parse('2025-03-06 08:00:00'), 'is_emergency' => true, 'emergency_reason' => 'Gas smell']),
            'D' => $this->make(['submitted_at' => Carbon::parse('2025-03-07 08:00:00'), 'is_confidential' => true]),
        ];
    }

    private function metrics(string $who, array $query = []): array
    {
        return $this->actingAs($this->user($who))->get(route('reports.index', $query))->assertOk()->viewData('metrics');
    }

    /** @return list<list<string>> the rows of a downloaded CSV (BOM removed) */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith(Csv::BOM, $content);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, strlen(Csv::BOM)));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function download(string $who, array $query = []): array
    {
        $response = $this->actingAs($this->user($who))->get(route('reports.export', $query))->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        return $this->csvRows($response->streamedContent());
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_the_page_opens_for_roles_with_view_analytics(): void
    {
        foreach (['maintenance', 'finance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get(route('reports.index'))->assertOk()->assertSee('Reports');
        }
    }

    public function test_everyone_else_gets_403_and_guests_go_to_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.export'))->assertRedirect(route('login'));

        foreach (['requester', 'depthead', 'treasurer', 'custodian'] as $who) {
            $this->actingAs($this->user($who))->get(route('reports.index'))->assertForbidden();
            $this->actingAs($this->user($who))->get(route('reports.export'))->assertForbidden();
        }
    }

    public function test_viewing_and_exporting_are_separate_capabilities(): void
    {
        // finance may look but not download
        config(['permissions.finance' => ['view_all_tickets', 'view_analytics']]);

        $page = $this->actingAs($this->user('finance'))->get(route('reports.index'))->assertOk();
        $page->assertDontSee(route('reports.export'));
        $this->actingAs($this->user('finance'))->get(route('reports.export'))->assertForbidden();
        $this->assertCount(0, AuditLog::where('action', AuditLogger::EXPORT)->get());

        // export without view
        config(['permissions.finance' => ['view_all_tickets', 'export_reports']]);
        $this->actingAs($this->user('finance'))->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->user('finance'))->get(route('reports.export'))->assertOk();
    }

    public function test_the_nav_link_shows_only_for_people_with_view_analytics(): void
    {
        $url = route('reports.index');

        foreach (['maintenance', 'finance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.index'))->assertOk()->assertSee($url);
        }
        foreach (['requester', 'depthead', 'treasurer', 'custodian'] as $who) {
            $this->actingAs($this->user($who))->get(route('tickets.index'))->assertOk()->assertDontSee($url);
        }
    }

    // ---- the numbers ----------------------------------------------------------------------------

    public function test_the_numbers_match_the_data(): void
    {
        $this->dataSet();

        $m = $this->metrics('maintenance');

        $this->assertSame(4, $m['total']);
        $this->assertSame(1, $m['emergency']);
        $this->assertSame(2, $m['overdue']);                 // C and D; the completed ones are never overdue
        $this->assertEquals(6.0, $m['avg_approval_hours']);  // (4 + 8) / 2
        $this->assertEquals(40.0, $m['avg_completion_hours']); // (30 + 50) / 2

        $status = collect($m['by_status'])->pluck('count', 'key');
        $this->assertSame(2, $status['work_completed']);
        $this->assertSame(2, $status['submitted']);
        $this->assertSame(0, $status['closed']);             // every status is listed, even with 0
        $this->assertCount(count(TicketStatus::cases()), $m['by_status']);

        $this->assertSame(4, array_sum(array_column($m['by_category'], 'count')));
        $this->assertSame(4, array_sum(array_column($m['by_department'], 'count')));
    }

    public function test_the_monthly_trend_fills_empty_months(): void
    {
        $this->dataSet();

        $m = $this->metrics('maintenance');

        $this->assertSame(['Jan 2025', 'Feb 2025', 'Mar 2025'], array_column($m['monthly'], 'label'));
        $this->assertSame([1, 0, 3], array_column($m['monthly'], 'count'));
    }

    public function test_workload_is_counted_per_assigned_staff(): void
    {
        $this->dataSet();

        $rows = collect($this->metrics('maintenance')['workload'])->keyBy('name');

        $this->assertSame(['assigned' => 1, 'open' => 0, 'completed' => 1], collect($rows['Test Maintenance Admin'])->only(['assigned', 'open', 'completed'])->all());
        $this->assertSame(['assigned' => 1, 'open' => 0, 'completed' => 1], collect($rows['Test Maintenance Staff 2'])->only(['assigned', 'open', 'completed'])->all());
        $this->assertCount(2, $rows);
    }

    public function test_an_empty_report_still_renders(): void
    {
        $response = $this->actingAs($this->user('president'))->get(route('reports.index'))->assertOk();
        $m = $response->viewData('metrics');

        $this->assertSame(0, $m['total']);
        $this->assertNull($m['avg_approval_hours']);
        $this->assertNull($m['avg_completion_hours']);
        $this->assertSame([], $m['monthly']);
        $response->assertSee('No assigned tickets in this range.');
    }

    // ---- date range -----------------------------------------------------------------------------

    public function test_the_date_range_filters_the_numbers_and_includes_the_whole_to_day(): void
    {
        $this->dataSet();

        $this->assertSame(1, $this->metrics('maintenance', ['to' => '2025-01-31'])['total']);
        $this->assertSame(3, $this->metrics('maintenance', ['from' => '2025-03-01'])['total']);
        $this->assertSame(1, $this->metrics('maintenance', ['from' => '2025-03-05', 'to' => '2025-03-05'])['total']);   // B was filed at 08:00 that day
        $this->assertSame(0, $this->metrics('maintenance', ['from' => '2026-12-31'])['total']);
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->user('maintenance'))
            ->get(route('reports.index', ['from' => '2025-03-10', 'to' => '2025-03-01']))
            ->assertSessionHasErrors('to');
    }

    // ---- visibility -----------------------------------------------------------------------------

    public function test_confidential_tickets_are_left_out_for_roles_without_view_confidential(): void
    {
        $tickets = $this->dataSet();

        foreach (['maintenance', 'president'] as $who) {
            $m = $this->metrics($who);
            $this->assertSame(4, $m['total'], $who);
            $this->assertSame(2, $m['overdue'], $who);
        }

        $finance = $this->metrics('finance');   // no view_confidential: D is hidden everywhere
        $this->assertSame(3, $finance['total']);
        $this->assertSame(1, $finance['overdue']);
        $this->assertSame(3, collect($finance['by_status'])->sum('count'));
        $this->assertSame([1, 0, 2], array_column($finance['monthly'], 'count'));

        // and the CSV: same rule
        $maintenanceNos = collect($this->download('maintenance'))->pluck(0)->all();
        $financeNos = collect($this->download('finance'))->pluck(0)->all();

        $this->assertContains($tickets['D']->ticket_no, $maintenanceNos);
        $this->assertNotContains($tickets['D']->ticket_no, $financeNos);
        $this->assertContains($tickets['C']->ticket_no, $financeNos);
    }

    // ---- CSV ------------------------------------------------------------------------------------

    public function test_the_csv_has_a_bom_a_header_and_one_row_per_visible_ticket(): void
    {
        $tickets = $this->dataSet();

        $rows = $this->download('maintenance');

        $this->assertSame(
            ['Ticket no', 'Status', 'Category', 'Department', 'Requester', 'Assigned to', 'Emergency', 'Confidential', 'Overdue',
                'Location', 'Description', 'Submitted', 'Due', 'Approved', 'Completed', 'Closed'],
            $rows[0]
        );
        $this->assertCount(5, $rows);   // header + A, B, C, D

        $byNo = collect($rows)->skip(1)->keyBy(0);
        $a = $byNo[$tickets['A']->ticket_no];
        $this->assertSame('Work Completed', $a[1]);
        $this->assertSame('Test Requester', $a[4]);
        $this->assertSame('Test Maintenance Admin', $a[5]);
        $this->assertSame('No', $a[6]);
        $this->assertSame('2025-01-10 08:00', $a[11]);
        $this->assertSame('2025-01-10 12:00', $a[13]);   // approved after 4 h
        $this->assertSame('Yes', $byNo[$tickets['C']->ticket_no][6]);   // emergency
        $this->assertSame('Yes', $byNo[$tickets['D']->ticket_no][7]);   // confidential
        $this->assertSame('Yes', $byNo[$tickets['C']->ticket_no][8]);   // overdue
    }

    public function test_the_csv_respects_the_date_range(): void
    {
        $tickets = $this->dataSet();

        $nos = collect($this->download('maintenance', ['from' => '2025-03-01', 'to' => '2025-03-05']))->skip(1)->pluck(0)->all();

        $this->assertSame([$tickets['B']->ticket_no], $nos);
    }

    public function test_cells_that_start_like_a_formula_are_neutralised(): void
    {
        $this->make(['description' => '=1+1', 'location' => '@SUM(A1:A9)']);
        $this->make(['description' => '+cmd|\' /C calc\'!A0', 'location' => '-2+3']);
        $this->make(['description' => 'He said "hi", then left', 'location' => "Room\n204"]);   // ordinary text must survive intact

        $rows = $this->download('maintenance');

        $descriptions = collect($rows)->skip(1)->pluck(10)->all();
        $locations = collect($rows)->skip(1)->pluck(9)->all();

        $this->assertContains("'=1+1", $descriptions);
        $this->assertContains("'@SUM(A1:A9)", $locations);
        $this->assertContains("'-2+3", $locations);
        $this->assertContains('He said "hi", then left', $descriptions);
        $this->assertContains("Room\n204", $locations);

        // no cell of the whole file may start with a formula character
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "Unsafe cell: {$cell}");
            }
        }
    }

    public function test_csv_cell_helper(): void
    {
        $this->assertSame("'=A1", Csv::cell('=A1'));
        $this->assertSame("'+1", Csv::cell('+1'));
        $this->assertSame("'-1", Csv::cell('-1'));
        $this->assertSame("'@x", Csv::cell('@x'));
        $this->assertSame("'\tx", Csv::cell("\tx"));
        $this->assertSame('plain - text', Csv::cell('plain - text'));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('Yes', Csv::cell(true));
        $this->assertSame('No', Csv::cell(false));
        $this->assertSame(-5, Csv::cell(-5));   // a real number is not text
    }

    // ---- audit trail ----------------------------------------------------------------------------

    public function test_every_export_is_logged(): void
    {
        $this->dataSet();

        $this->download('maintenance');
        $this->download('finance', ['from' => '2025-03-01', 'to' => '2025-03-31']);

        $rows = AuditLog::where('action', AuditLogger::EXPORT)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertSame($this->user('maintenance')->id, $rows[0]->user_id);
        $this->assertSame(4, $rows[0]->meta['rows']);
        $this->assertSame('csv', $rows[0]->meta['format']);
        $this->assertStringContainsString('Exported tickets report (CSV): 4 tickets', $rows[0]->description);

        $this->assertSame($this->user('finance')->id, $rows[1]->user_id);
        $this->assertSame(2, $rows[1]->meta['rows']);   // finance does not see the confidential one
        $this->assertSame('2025-03-01', $rows[1]->meta['from']);
        $this->assertSame('2025-03-31', $rows[1]->meta['to']);
    }

    public function test_a_failed_log_does_not_stop_the_download(): void
    {
        $this->make();
        AuditLog::creating(fn () => throw new \RuntimeException('audit table exploded'));

        $rows = $this->download('maintenance');

        $this->assertCount(2, $rows);
    }

    // ---- charts come from the bundle, not a CDN -------------------------------------------------

    public function test_charts_are_bundled_and_the_page_has_their_data(): void
    {
        $this->dataSet();

        $response = $this->actingAs($this->user('maintenance'))->get(route('reports.index'))->assertOk();

        $response->assertSee('id="report-charts"', false);
        foreach (['status', 'category', 'monthly', 'department'] as $key) {
            $response->assertSee('id="chart-'.$key.'"', false);
        }
        // Font Awesome comes from cdnjs (LCCT layout), so only a CHART library from a CDN counts as a failure.
        foreach (['cdn.jsdelivr.net', 'unpkg.com', 'chart.umd', 'cdnjs.cloudflare.com/ajax/libs/Chart.js', 'cdnjs.cloudflare.com/ajax/libs/chart.js'] as $cdn) {
            $response->assertDontSee($cdn);
        }

        $charts = $response->viewData('charts');
        $this->assertSame(['Jan 2025', 'Feb 2025', 'Mar 2025'], $charts['monthly']['labels']);
        $this->assertSame([1, 0, 3], $charts['monthly']['data']);

        $this->assertStringContainsString("from 'chart.js'", file_get_contents(base_path('resources/js/reports.js')));
        $this->assertArrayHasKey('chart.js', json_decode(file_get_contents(base_path('package.json')), true)['dependencies']);
    }
}
