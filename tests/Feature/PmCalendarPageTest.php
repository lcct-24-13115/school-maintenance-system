<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\PmTicketGenerator;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PM E4: the maintenance calendar PAGE (the month grid and the list). The JSON feed is in MaintenanceCalendarTest. */
class PmCalendarPageTest extends TestCase
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

        $this->travelTo(Carbon::parse('2026-11-08 09:00:00'));
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function schedule(array $over = []): PmSchedule
    {
        return PmSchedule::create(array_merge([
            'title' => 'Aircon Cleaning',
            'facility_id' => Facility::where('name', 'Conference Room')->value('id'),
            'category_id' => Category::where('name', 'Air Conditioning')->value('id'),
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'assigned_to' => $this->user('maintenance')->id,
            'frequency_type' => 'monthly',
            'frequency_interval' => 1,
            'start_date' => '2026-11-15',
            'lead_time_days' => 7,
            'is_active' => true,
            'created_by' => $this->user('maintenance')->id,
        ], $over));
    }

    public function test_who_may_open_the_page(): void
    {
        $url = route('maintenance-calendar.index');

        $this->get($url)->assertRedirect();

        foreach (['maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who))->get($url)->assertOk()->assertSee('Maintenance calendar');
        }

        foreach (['finance', 'custodian', 'requester', 'depthead', 'treasurer'] as $who) {
            $this->actingAs($this->user($who))->get($url)->assertForbidden();
        }
    }

    public function test_the_month_shows_the_coming_dates_and_the_counters(): void
    {
        $this->schedule();

        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index'))
            ->assertOk()
            ->assertSee('November 2026')
            ->assertSee('Aircon Cleaning')
            ->assertSee('Nov 15, 2026')
            ->assertSee('Not made yet')
            ->assertViewHas('counts', fn ($c) => $c['upcoming'] === 1 && $c['open'] === 0 && $c['overdue'] === 0);
    }

    public function test_the_grid_is_made_of_whole_weeks(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index', ['month' => '2026-11']))
            ->assertOk()
            ->assertViewHas('weeks', fn ($weeks) => count($weeks) >= 4 && count($weeks) <= 6
                && collect($weeks)->every(fn ($week) => count($week) === 7)
                && $weeks[0][0]['date'] === '2026-11-01'      // Nov 1, 2026 is a Sunday
                && $weeks[0][0]['in_month'] === true);
    }

    public function test_next_and_previous_month(): void
    {
        $this->schedule();
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->get(route('maintenance-calendar.index'))
            ->assertOk()
            ->assertSee('month=2026-12', false)
            ->assertSee('month=2026-10', false)
            ->assertDontSee('This month');

        $this->actingAs($admin)->get(route('maintenance-calendar.index', ['month' => '2026-12']))
            ->assertOk()
            ->assertSee('December 2026')
            ->assertSee('Dec 15, 2026')
            ->assertSee('This month')
            ->assertViewHas('counts', fn ($c) => $c['upcoming'] === 1);
    }

    public function test_a_ticket_replaces_its_coming_date_and_links_to_the_ticket(): void
    {
        $schedule = $this->schedule();
        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08', true);

        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index'))
            ->assertOk()
            ->assertSee('/tickets/'.$ticket->id)
            ->assertSee($ticket->ticket_no)
            ->assertDontSee('Not made yet')
            ->assertViewHas('counts', fn ($c) => $c['open'] === 1 && $c['upcoming'] === 0);
    }

    public function test_a_ticket_past_its_due_date_is_overdue(): void
    {
        $schedule = $this->schedule();
        [, $ticket] = PmTicketGenerator::generate($schedule, '2026-11-08', true);

        // the generator never makes a past date, so the due date is set by hand
        DB::table('requests')->where('id', $ticket->id)->update(['pm_due_date' => '2026-11-02']);

        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index'))
            ->assertOk()
            ->assertViewHas('counts', fn ($c) => $c['overdue'] === 1 && $c['open'] === 0);
    }

    public function test_the_filters(): void
    {
        $this->schedule(['title' => 'Aircon Cleaning']);
        $this->schedule([
            'title' => 'Generator Checkup',
            'facility_id' => Facility::where('name', 'Chapel')->value('id'),
            'start_date' => '2026-11-20',
        ]);
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->get(route('maintenance-calendar.index'))
            ->assertSee('Aircon Cleaning')->assertSee('Generator Checkup');

        $this->actingAs($admin)
            ->get(route('maintenance-calendar.index', ['facility_id' => Facility::where('name', 'Chapel')->value('id')]))
            ->assertOk()
            ->assertSee('Generator Checkup')
            ->assertDontSee('Aircon Cleaning');

        $this->actingAs($admin)->get(route('maintenance-calendar.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee('Nothing is planned in this month.');
    }

    public function test_a_bad_month_or_status_is_refused(): void
    {
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->get(route('maintenance-calendar.index', ['month' => '2026-13']))
            ->assertSessionHasErrors('month');

        $this->actingAs($admin)->get(route('maintenance-calendar.index', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    public function test_many_dates_on_one_day_are_folded(): void
    {
        foreach (range(1, 5) as $n) {
            $this->schedule(['title' => "Same day PM {$n}"]);
        }

        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index'))
            ->assertOk()
            ->assertSee('+2 more');
    }

    public function test_the_president_sees_the_dates_but_no_link_to_change_a_schedule(): void
    {
        $schedule = $this->schedule();
        $edit = '/admin/pm-schedules/'.$schedule->id.'/edit';

        $this->actingAs($this->user('maintenance'))->get(route('maintenance-calendar.index'))
            ->assertOk()->assertSee($edit);

        $this->actingAs($this->user('president'))->get(route('maintenance-calendar.index'))
            ->assertOk()->assertSee('Aircon Cleaning')->assertDontSee($edit);
    }

    public function test_the_menu_has_the_calendar_for_those_who_may_look(): void
    {
        $this->actingAs($this->user('president'))->get(route('pm-reports.index'))
            ->assertOk()->assertSee(route('maintenance-calendar.index'));

        // Finance has the report but not the calendar
        $this->actingAs($this->user('finance'))->get(route('pm-reports.index'))
            ->assertOk()->assertDontSee(route('maintenance-calendar.index'));
    }
}
