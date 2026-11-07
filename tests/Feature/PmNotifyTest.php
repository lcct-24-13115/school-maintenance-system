<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Mail\TicketNotificationMail;
use App\Models\Category;
use App\Models\Facility;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FormActivity;
use App\Services\PmNotifier;
use App\Services\PmTicketGenerator;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** PM integration Phase C: new-ticket / reminder / overdue / supervisor alerts (in the app and by email), sent once. */
class PmNotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:00:00', config('app.timezone')));
        Mail::fake();
        Notification::fake();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);
    }
    use RefreshDatabase;

    private function admin(): User
    {
        return User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
    }

    private function president(): User
    {
        return User::where('email', 'president@lcctanauan.edu.ph')->firstOrFail();
    }

    /** A PM ticket due Nov 15 (made today, Nov 8, and assigned to the Head of Maintenance). */
    private function pmTicket(): MaintenanceRequest
    {
        $schedule = PmSchedule::create([
            'title' => 'Aircon Cleaning',
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'category_id' => Category::where('name', 'Air Conditioning')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->admin()->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->admin()->id,
        ]);

        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08');

        return $ticket->refresh();
    }

    public function test_a_new_pm_ticket_is_announced_once_in_the_app_and_by_email(): void
    {
        $ticket = $this->pmTicket();
        Notification::fake();
        Mail::fake();

        $this->assertSame(1, PmNotifier::newTickets());

        Notification::assertSentTo($this->admin(), FormActivity::class);
        Mail::assertQueued(TicketNotificationMail::class, fn ($m) => $m->event === 'pm_new' && $m->hasTo($this->admin()->email));
        $this->assertNotNull($ticket->refresh()->pm_notified_at);

        $this->assertSame(0, PmNotifier::newTickets());   // never twice
        Notification::assertSentToTimes($this->admin(), FormActivity::class, 1);
    }

    public function test_the_reminder_goes_out_x_days_before_the_due_date_only_once(): void
    {
        $ticket = $this->pmTicket();
        Notification::fake();
        Mail::fake();

        $this->assertSame(0, PmNotifier::reminders('2026-11-11'));   // 4 days before: too early (default is 3)
        $this->assertSame(1, PmNotifier::reminders('2026-11-12'));   // 3 days before
        $this->assertSame(0, PmNotifier::reminders('2026-11-13'));   // already reminded

        Mail::assertQueued(TicketNotificationMail::class, fn ($m) => $m->event === 'pm_reminder' && $m->hasTo($this->admin()->email));
        $this->assertNotNull($ticket->refresh()->pm_reminded_at);
    }

    public function test_reminders_can_be_turned_off(): void
    {
        $this->pmTicket();
        config(['school.pm.reminder_days_before' => 0]);

        $this->assertSame(0, PmNotifier::reminders('2026-11-14'));
    }

    public function test_a_ticket_is_marked_overdue_the_day_after_the_due_date_with_a_history_line(): void
    {
        $ticket = $this->pmTicket();
        Notification::fake();
        Mail::fake();

        $this->assertSame(0, PmNotifier::overdue('2026-11-15'));   // due today is not overdue yet
        $this->assertSame(1, PmNotifier::overdue('2026-11-16'));
        $this->assertSame(0, PmNotifier::overdue('2026-11-17'));   // once

        $ticket->refresh();
        $this->assertNotNull($ticket->pm_overdue_at);
        $this->assertTrue($ticket->statusLogs()->where('note', 'like', '%overdue%')->exists());
        Mail::assertQueued(TicketNotificationMail::class, fn ($m) => $m->event === 'pm_overdue');
        $this->assertSame(TicketStatus::Assigned, $ticket->status);   // still workable
    }

    public function test_the_supervisor_is_told_after_the_grace_days_and_the_status_does_not_change(): void
    {
        $ticket = $this->pmTicket();
        $president = $this->president();
        Notification::fake();
        Mail::fake();

        $this->assertSame(0, PmNotifier::escalate('2026-11-16'));   // 1 day overdue: default grace is 2
        $this->assertSame(1, PmNotifier::escalate('2026-11-17'));
        $this->assertSame(0, PmNotifier::escalate('2026-11-18'));

        Notification::assertSentTo($president, FormActivity::class);
        Mail::assertQueued(TicketNotificationMail::class, fn ($m) => $m->event === 'pm_escalated' && $m->hasTo($president->email));

        $ticket->refresh();
        $this->assertNotNull($ticket->pm_escalated_at);
        $this->assertSame(TicketStatus::Assigned, $ticket->status);
    }

    public function test_a_completed_pm_ticket_gets_no_more_alerts(): void
    {
        $ticket = $this->pmTicket();
        TicketWorkflow::startWork($ticket->refresh(), $this->admin());
        TicketWorkflow::markWorkCompleted($ticket->refresh(), $this->admin());

        Notification::fake();
        Mail::fake();

        $this->assertSame(0, PmNotifier::reminders('2026-11-13'));
        $this->assertSame(0, PmNotifier::overdue('2026-11-20'));
        $this->assertSame(0, PmNotifier::escalate('2026-11-20'));
    }

    public function test_the_hourly_ticket_escalation_leaves_pm_tickets_alone(): void
    {
        $ticket = $this->pmTicket();

        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));   // well past the ticket's target date
        $this->artisan('tickets:escalate')->assertSuccessful();

        $this->assertSame(TicketStatus::Assigned, $ticket->refresh()->status);
    }

    public function test_the_command_sends_everything_and_a_dry_run_changes_nothing(): void
    {
        $ticket = $this->pmTicket();
        $this->president();

        $this->artisan('pm:notify', ['--dry-run' => true, '--date' => '2026-11-17'])->assertSuccessful();
        $this->assertNull($ticket->refresh()->pm_overdue_at);

        $this->artisan('pm:notify', ['--date' => '2026-11-17'])->assertSuccessful();

        $ticket->refresh();
        $this->assertNotNull($ticket->pm_notified_at);
        $this->assertNotNull($ticket->pm_overdue_at);
        $this->assertNotNull($ticket->pm_escalated_at);
    }

    public function test_the_ticket_page_shows_that_the_pm_is_overdue(): void
    {
        $ticket = $this->pmTicket();
        PmNotifier::overdue('2026-11-16');

        $this->actingAs($this->admin())->get(route('tickets.show', $ticket))
            ->assertOk()->assertSee('PM is overdue since');
    }
}