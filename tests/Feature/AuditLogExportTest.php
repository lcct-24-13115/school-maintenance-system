<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Export CSV" on the audit log page: capability, filters, CSV safety and the audit trail of the export itself. */
class AuditLogExportTest extends TestCase
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

    public function test_only_roles_that_may_view_the_audit_log_can_export(): void
    {
        $this->actingAs($this->user('requester'))->get(route('admin.audit-logs.export'))->assertForbidden();
        $this->actingAs($this->user('finance'))->get(route('admin.audit-logs.export'))->assertForbidden();
    }

    public function test_the_button_shows_on_the_page(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.audit-logs.index'))->assertOk()->assertSee('Export CSV');
    }

    public function test_the_admin_downloads_a_csv_with_a_bom_and_a_header(): void
    {
        AuditLogger::log('login', 'Signed in for the export test', user: $this->user('requester'));

        $response = $this->actingAs($this->user('maintenance'))->get(route('admin.audit-logs.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('When,User,Email,Action,Description,"IP address"', $body);
        $this->assertStringContainsString('Signed in for the export test', $body);
        $this->assertStringContainsString('requester@lcctanauan.edu.ph', $body);
    }

    public function test_the_filters_of_the_page_apply_to_the_file(): void
    {
        AuditLogger::log('login', 'keep-this-login-row', user: $this->user('requester'));
        AuditLogger::log('logout', 'drop-this-logout-row', user: $this->user('requester'));

        $body = $this->actingAs($this->user('maintenance'))
            ->get(route('admin.audit-logs.export', ['action' => 'login']))
            ->streamedContent();

        $this->assertStringContainsString('keep-this-login-row', $body);
        $this->assertStringNotContainsString('drop-this-logout-row', $body);
    }

    public function test_text_that_looks_like_a_formula_is_neutralised(): void
    {
        AuditLogger::log('login', '=HYPERLINK("http://evil.test")', user: $this->user('requester'));

        $body = $this->actingAs($this->user('maintenance'))->get(route('admin.audit-logs.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $body);
    }

    public function test_the_export_is_itself_written_to_the_audit_log(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.audit-logs.export'))->assertOk();

        $this->assertTrue(
            AuditLog::where('action', 'export')->where('description', 'like', 'Exported audit log%')->exists()
        );
    }

    public function test_the_president_role_may_export_when_it_exists(): void
    {
        $this->actingAs($this->user('president'))->get(route('admin.audit-logs.export'))->assertOk();
    }
}
