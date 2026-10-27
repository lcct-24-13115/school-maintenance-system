<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** Merge Phase M3, task 1: the table, the read-only model, the logger and the capability. */
class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    public function test_log_writes_a_row_for_the_given_user_and_subject(): void
    {
        $requester = $this->user('requester');

        $row = AuditLogger::log(AuditLogger::USER_UPDATED, 'Changed fields: name', $requester, $this->user('maintenance'), ['fields' => ['name']]);

        $this->assertNotNull($row);
        $this->assertSame($this->user('maintenance')->id, $row->user_id);
        $this->assertSame('user_updated', $row->action);
        $this->assertSame($requester->getMorphClass(), $row->subject_type);
        $this->assertSame($requester->id, $row->subject_id);
        $this->assertSame(['fields' => ['name']], $row->meta);
        $this->assertNotNull($row->created_at);
        $this->assertTrue($row->subject->is($requester));
        $this->assertTrue($row->user->is($this->user('maintenance')));
    }

    public function test_the_signed_in_user_is_used_when_none_is_given(): void
    {
        $this->actingAs($this->user('president'));

        $row = AuditLogger::log(AuditLogger::LOGIN, 'Signed in');

        $this->assertSame($this->user('president')->id, $row->user_id);
    }

    public function test_a_system_event_has_no_user_subject_ip_or_agent(): void
    {
        $row = AuditLogger::log(AuditLogger::EXPORT, 'Exported something');

        $this->assertNull($row->user_id);
        $this->assertNull($row->subject_type);
        $this->assertNull($row->subject_id);
        $this->assertNull($row->meta);
    }

    public function test_the_ip_address_and_user_agent_come_from_the_request(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/x', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => str_repeat('A', 400),
        ]));

        $row = AuditLogger::log(AuditLogger::LOGIN, 'Signed in', user: $this->user('requester'));

        $this->assertSame('203.0.113.7', $row->ip_address);
        $this->assertSame(255, strlen($row->user_agent));
    }

    public function test_the_description_is_cut_to_1000_characters(): void
    {
        $row = AuditLogger::log(AuditLogger::EXPORT, str_repeat('x', 5000));

        $this->assertSame(1000, mb_strlen($row->description));
    }

    public function test_passwords_and_tokens_never_reach_the_table(): void
    {
        $row = AuditLogger::log(
            AuditLogger::LOGIN_FAILED,
            'Failed sign-in for a@b.test password=hunter2 token: abc123 Authorization: Bearer xyz999',
            meta: [
                'email' => 'a@b.test',
                'password' => 'hunter2',
                'remember_token' => 'abc',
                'nested' => ['_token' => 'csrf', 'api_secret' => 's', 'ok' => 'kept'],
            ],
        );

        $stored = json_encode($row->fresh()->toArray());

        foreach (['hunter2', 'abc123', 'xyz999', 'csrf'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertSame(['email' => 'a@b.test', 'nested' => ['ok' => 'kept']], $row->meta);
        $this->assertStringContainsString('password=[redacted]', $row->description);
        $this->assertStringContainsString('a@b.test', $row->description);   // ordinary text is kept
    }

    public function test_a_row_cannot_be_updated_or_deleted(): void
    {
        $row = AuditLogger::log(AuditLogger::LOGIN, 'Signed in');

        try {
            $row->update(['description' => 'edited']);
            $this->fail('Updating an audit row must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('Deleting an audit row must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        $this->assertSame('Signed in', $row->fresh()->description);
    }

    public function test_the_logger_never_throws_even_when_the_write_fails(): void
    {
        // an action name is required by the table; a broken connection or any other failure must not leak out
        \Illuminate\Support\Facades\Schema::drop('audit_logs');

        $this->assertNull(AuditLogger::log(AuditLogger::LOGIN, 'Signed in'));
    }

    public function test_a_removed_user_leaves_the_row_with_no_user(): void
    {
        $user = User::factory()->create();
        $row = AuditLogger::log(AuditLogger::LOGIN, 'Signed in', user: $user);

        $user->forceDelete();

        $this->assertNull($row->fresh()->user_id);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_only_maintenance_admin_and_president_have_the_capability(): void
    {
        foreach (['maintenance', 'president'] as $name) {
            $this->assertTrue($this->user($name)->can_do('view_audit_logs'), $name);
        }
        foreach (['requester', 'finance', 'depthead', 'treasurer', 'custodian'] as $name) {
            $this->assertFalse($this->user($name)->can_do('view_audit_logs'), $name);
        }
    }

    public function test_every_action_name_is_unique_and_fits_the_column(): void
    {
        $actions = AuditLogger::actions();

        $this->assertSame($actions, array_values(array_unique($actions)));
        foreach ($actions as $action) {
            $this->assertLessThanOrEqual(50, strlen($action));
        }
    }
}
