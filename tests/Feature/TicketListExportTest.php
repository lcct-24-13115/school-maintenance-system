<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** "Export CSV" on My Tickets / Ticket History: capability, filters, history split and the audit trail. */
class TicketListExportTest extends TestCase
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

    private function csv($response): string
    {
        return $response->streamedContent();
    }

    public function test_a_requester_gets_403_on_both_exports(): void
    {
        $this->actingAs($this->user('requester'));

        $this->get(route('tickets.export'))->assertForbidden();
        $this->get(route('tickets.history.export'))->assertForbidden();
    }

    public function test_the_button_only_shows_to_roles_that_may_export(): void
    {
        $this->make();

        $this->actingAs($this->user('requester'))->get(route('tickets.index'))->assertOk()->assertDontSee('Export CSV');
        $this->actingAs($this->user('maintenance'))->get(route('tickets.index'))->assertOk()->assertSee('Export CSV');
    }

    public function test_the_admin_downloads_the_active_list_with_a_bom_and_a_header(): void
    {
        $a = $this->make();

        $response = $this->actingAs($this->user('maintenance'))->get(route('tickets.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $this->csv($response);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Ticket no', $body);
        $this->assertStringContainsString($a->ticket_no, $body);
    }

    public function test_the_filters_of_the_list_apply_to_the_file(): void
    {
        $old = $this->make(['submitted_at' => Carbon::parse('2025-01-10 08:00:00')]);
        $new = $this->make(['submitted_at' => Carbon::parse('2025-03-10 08:00:00')]);

        $body = $this->csv($this->actingAs($this->user('maintenance'))
            ->get(route('tickets.export', ['from' => '2025-03-01'])));

        $this->assertStringContainsString($new->ticket_no, $body);
        $this->assertStringNotContainsString($old->ticket_no, $body);
    }

    public function test_active_and_history_exports_do_not_mix(): void
    {
        $active = $this->make();
        $done = $this->make(['status' => TicketStatus::Cancelled]);

        $admin = $this->actingAs($this->user('maintenance'));

        $activeCsv = $this->csv($admin->get(route('tickets.export')));
        $this->assertStringContainsString($active->ticket_no, $activeCsv);
        $this->assertStringNotContainsString($done->ticket_no, $activeCsv);

        $historyCsv = $this->csv($admin->get(route('tickets.history.export')));
        $this->assertStringContainsString($done->ticket_no, $historyCsv);
        $this->assertStringNotContainsString($active->ticket_no, $historyCsv);
    }

    public function test_a_confidential_ticket_is_left_out_for_finance(): void
    {
        $secret = $this->make(['is_confidential' => true]);
        $plain = $this->make();

        $body = $this->csv($this->actingAs($this->user('finance'))->get(route('tickets.export')));

        $this->assertStringContainsString($plain->ticket_no, $body);
        $this->assertStringNotContainsString($secret->ticket_no, $body);
    }

    public function test_every_export_is_written_to_the_audit_log(): void
    {
        $this->make();

        $this->actingAs($this->user('maintenance'))->get(route('tickets.export'))->assertOk();

        $this->assertTrue(AuditLog::where('action', 'export')->where('description', 'like', 'Exported ticket list%')->exists());
    }
}
