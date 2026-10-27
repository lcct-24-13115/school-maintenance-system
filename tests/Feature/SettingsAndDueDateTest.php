<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Merge Phase M1: editable settings, ticket due date (SLA) and overdue. */
class SettingsAndDueDateTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function open(array $overrides = []): MaintenanceRequest
    {
        $requester = $this->user('requester@lcctanauan.edu.ph');

        return TicketWorkflow::open($requester, $overrides + [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Library',
            'description' => 'Broken window',
        ]);
    }

    public function test_settings_fall_back_to_the_config_defaults_until_changed(): void
    {
        $this->seedBase();

        $this->assertSame(120, Setting::int('sla_hours_routine'));
        $this->assertSame(24, Setting::int('sla_hours_emergency'));

        Setting::put('sla_hours_routine', 48);

        $this->assertSame(48, Setting::int('sla_hours_routine'));
    }

    public function test_a_new_ticket_gets_a_due_date_from_the_routine_sla(): void
    {
        $this->seedBase();
        Setting::put('sla_hours_routine', 72);

        $ticket = $this->open();

        $this->assertNotNull($ticket->due_at);
        $this->assertSame(72, (int) $ticket->submitted_at->diffInHours($ticket->due_at));
    }

    public function test_an_emergency_ticket_uses_the_emergency_sla(): void
    {
        $this->seedBase();

        $ticket = $this->open(['is_emergency' => true, 'emergency_reason' => 'Burst pipe']);

        $this->assertSame(24, (int) $ticket->submitted_at->diffInHours($ticket->due_at));
    }

    public function test_flagging_and_clearing_an_emergency_recomputes_the_due_date(): void
    {
        $this->seedBase();
        $staff = $this->user('maintenance@lcctanauan.edu.ph');
        $ticket = $this->open();

        $ticket = TicketWorkflow::flagEmergency($ticket, $staff, 'Electrical hazard');
        $this->assertSame(24, (int) $ticket->submitted_at->diffInHours($ticket->due_at));

        $ticket = TicketWorkflow::clearEmergency($ticket, $staff);
        $this->assertSame(120, (int) $ticket->submitted_at->diffInHours($ticket->due_at));
    }

    public function test_overdue_scope_only_counts_pending_tickets_past_their_due_date(): void
    {
        $this->seedBase();
        $late = $this->open();
        $fine = $this->open();
        $done = $this->open();

        $late->forceFill(['due_at' => now()->subDay()])->saveQuietly();
        $fine->forceFill(['due_at' => now()->addDay()])->saveQuietly();
        $done->forceFill(['due_at' => now()->subDay(), 'status' => TicketStatus::Disapproved])->saveQuietly();

        $ids = MaintenanceRequest::overdue()->pluck('id')->all();

        $this->assertSame([$late->id], $ids);
        $this->assertTrue($late->fresh()->isOverdue());
        $this->assertFalse($fine->fresh()->isOverdue());
        $this->assertFalse($done->fresh()->isOverdue());
    }

    public function test_only_the_maintenance_admin_can_open_the_settings_page(): void
    {
        $this->seedBase();

        $this->actingAs($this->user('maintenance@lcctanauan.edu.ph'))->get(route('admin.settings.edit'))->assertOk();

        foreach (['requester', 'finance', 'president', 'depthead', 'custodian', 'treasurer'] as $name) {
            $this->actingAs($this->user($name.'@lcctanauan.edu.ph'))->get(route('admin.settings.edit'))->assertForbidden();
            $this->actingAs($this->user($name.'@lcctanauan.edu.ph'))->put(route('admin.settings.update'), [])->assertForbidden();
        }
    }

    public function test_the_admin_can_save_settings_and_invalid_values_are_refused(): void
    {
        $this->seedBase();
        $admin = $this->user('maintenance@lcctanauan.edu.ph');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 96, 'sla_hours_emergency' => 12, 'reopen_window_days' => 7,
        ])->assertRedirect(route('admin.settings.edit'));

        $this->assertSame(96, Setting::int('sla_hours_routine'));
        $this->assertSame(7, Setting::int('reopen_window_days'));

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 0, 'sla_hours_emergency' => 'abc', 'reopen_window_days' => 7,
        ])->assertSessionHasErrors(['sla_hours_routine', 'sla_hours_emergency']);

        $this->assertSame(96, Setting::int('sla_hours_routine'));
    }

    public function test_recalculate_open_redates_pending_tickets_only(): void
    {
        $this->seedBase();
        $admin = $this->user('maintenance@lcctanauan.edu.ph');
        $pending = $this->open();
        $closedLike = $this->open();
        $closedLike->forceFill(['status' => TicketStatus::Disapproved])->saveQuietly();
        $before = $closedLike->fresh()->due_at;

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'sla_hours_routine' => 10, 'sla_hours_emergency' => 5, 'reopen_window_days' => 14, 'recalculate_open' => 1,
        ])->assertRedirect();

        $this->assertSame(10, (int) $pending->fresh()->submitted_at->diffInHours($pending->fresh()->due_at));
        $this->assertTrue($before->equalTo($closedLike->fresh()->due_at));
    }

    public function test_the_overdue_filter_on_the_ticket_list_works(): void
    {
        $this->seedBase();
        $admin = $this->user('maintenance@lcctanauan.edu.ph');
        $late = $this->open();
        $fine = $this->open();
        $late->forceFill(['due_at' => now()->subHours(3)])->saveQuietly();

        $response = $this->actingAs($admin)->get(route('tickets.index', ['overdue' => 1]))->assertOk();

        $response->assertSee($late->ticket_no);
        $response->assertDontSee($fine->ticket_no);
        $response->assertSee('Overdue by');
    }
}
