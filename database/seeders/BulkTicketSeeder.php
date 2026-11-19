<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 100 TEST tickets (change with SEED_TICKETS=250), made through the REAL workflow (approval chain, history,
 * assignment rules), so they behave exactly like tickets filed by people. No emergency flags. Every one has reason "TEST DATA".
 *
 *   php artisan db:seed --class=BulkTicketSeeder
 *
 * No emails and no in-app alerts are sent. Safe to run again: it only ADDS more test tickets.
 * Remove them all:  php artisan tinker --execute="App\Models\MaintenanceRequest::where('reason','TEST DATA')->delete();"
 */
class BulkTicketSeeder extends Seeder
{
    /** 100 slots; the mix of where the tickets end up. */
    private const MIX = [
        'waiting_vp' => 10, 'waiting_finance' => 8, 'waiting_president' => 8, 'disapproved' => 8, 'cancelled' => 4,
        'approved' => 12, 'claimed' => 6, 'assigned' => 12, 'in_progress' => 12, 'waiting_user' => 4,
        'completed' => 8, 'closed' => 8,
    ];

    private const PLACES = [
        'Grade 5 Room 204', 'Grade 6 Comfort Room', 'Grade 4 Room 101', 'Library entrance', 'Computer Laboratory',
        'Science Laboratory', 'Faculty Room', 'Guidance Office', 'Canteen', 'Main Gate', 'Gymnasium',
        'Registrar Office', 'Chapel', 'Cashier Window', 'Admin Building 2nd floor', 'Covered Court',
    ];

    private const PROBLEMS = [
        'Faucet keeps leaking after the washer was replaced.', 'Ceiling fan makes a loud noise and wobbles.',
        'Two fluorescent lights are not working.', 'Door lock is jammed, the room cannot be locked.',
        'Window glass is cracked.', 'Aircon is not cooling.', 'Clogged sink in the comfort room.',
        'Electrical outlet sparks when plugged in.', 'Broken student armchairs, 5 pieces.', 'Whiteboard is falling off the wall.',
        'Water pump is not delivering water to the second floor.', 'Roof leaks when it rains.', 'Projector will not turn on.',
        'Paint is peeling on the corridor wall.', 'Gate hinge is broken.', 'Toilet does not flush.',
    ];

    private array $staff = [];

    private int $failed = 0;

    public function run(): void
    {
        config(['school.ticket_emails' => false]);
        Notification::fake();   // no in-app alerts for test data

        $vp = $this->user(Role::VICE_PRINCIPAL);
        $finance = $this->user(Role::FINANCE);
        $president = $this->user(Role::PRESIDENT);
        $head = $this->user(Role::MAINTENANCE_ADMIN);
        $categories = Category::where('is_active', true)->get();

        if (! $vp || ! $finance || ! $president || ! $head || $categories->isEmpty()) {
            $this->command?->warn('Run RoleSeeder, DepartmentSeeder, CategorySeeder and UserSeeder first (php artisan db:seed).');

            return;
        }

        $signer = [Role::VICE_PRINCIPAL => $vp, Role::FINANCE => $finance, Role::PRESIDENT => $president];
        $requesters = $this->requesters();
        $this->staff = $this->maintenanceStaff($head);

        mt_srand(2026);
        $plan = [];
        foreach (self::MIX as $state => $n) {
            $plan = array_merge($plan, array_fill(0, $n, $state));
        }
        shuffle($plan);

        $total = (int) env('SEED_TICKETS', 100);
        $made = 0;

        for ($i = 0; $i < $total; $i++) {
            $state = $plan[$i % count($plan)];
            $requester = $requesters[$i % count($requesters)];

            try {
                $ticket = TicketWorkflow::open($requester, [
                    'department_id' => $requester->department_id ?? Department::query()->orderBy('id')->value('id'),
                    'category_id' => $categories[mt_rand(0, $categories->count() - 1)]->id,
                    'location' => self::PLACES[mt_rand(0, count(self::PLACES) - 1)],
                    'description' => self::PROBLEMS[mt_rand(0, count(self::PROBLEMS) - 1)],
                    'reason' => 'TEST DATA',
                    'is_confidential' => $i % 33 === 7,
                ]);

                $ticket = $this->advance($ticket, $state, $requester, $signer, $head);
                $this->backdate($ticket);
                $made++;
            } catch (Throwable $e) {
                $this->failed++;
                $this->command?->warn("Ticket {$i} ({$state}) stopped: ".$e->getMessage());
            }
        }

        $this->command?->info("Done: {$made} test tickets made, {$this->failed} stopped early. Tickets marked reason = \"TEST DATA\".");
    }

    private function advance(MaintenanceRequest $t, string $state, User $requester, array $signer, User $head): MaintenanceRequest
    {
        $sign = function (int $steps) use (&$t, $signer) {
            for ($k = 0; $k < $steps; $k++) {
                $role = $t->approvals()->where('status', 'pending')->value('role_slug');
                $t = TicketWorkflow::authorize($t, $signer[$role], [Role::VICE_PRINCIPAL => 'Reviewed and endorsed for processing.', Role::FINANCE => 'Budget verified and funds cleared.', Role::PRESIDENT => 'Final approval granted. Proceed with the work.'][$role] ?? 'Approved.', [], $role === Role::FINANCE ? mt_rand(0, 5000) : null);
            }
        };

        $staff = $this->staff[mt_rand(0, count($this->staff) - 1)];
        $date = now()->addDays(mt_rand(-3, 14))->toDateString();   // some already late, to test the Due badge

        switch ($state) {
            case 'waiting_vp':
                return $t;
            case 'waiting_finance':
                $sign(1);

                return $t;
            case 'waiting_president':
                $sign(2);

                return $t;
            case 'disapproved':
                $sign(mt_rand(0, 2));
                $role = $t->approvals()->where('status', 'pending')->value('role_slug');

                return TicketWorkflow::disapprove($t, $signer[$role], 'Not needed at this time.');
            case 'cancelled':
                return TicketWorkflow::cancel($t, $requester, 'Already fixed by someone else.');
        }

        $sign(3);   // everything below is fully approved

        if ($state === 'approved') {
            return $t;
        }
        $t = TicketWorkflow::assign($t, $staff, $head, 'Test data.', $date);

        return match ($state) {
            'assigned', 'claimed' => $t,
            'in_progress' => TicketWorkflow::startWork($t, $staff),
            'waiting_user' => TicketWorkflow::requestInfo($t, $head, 'Which room exactly, and who can open it for us?'),
            'completed', 'closed' => $this->finish($t, $staff, $head, $state === 'closed'),
        };
    }

    private function finish(MaintenanceRequest $t, User $staff, User $head, bool $close): MaintenanceRequest
    {
        $t = TicketWorkflow::startWork($t, $staff);
        $t = TicketWorkflow::markWorkCompleted($t, $staff, mt_rand(0, 2) === 0 ? 'Spare part had to be ordered.' : null);

        if ($close) {
            try {
                $t = TicketWorkflow::close($t, $head);
            } catch (Throwable) {
                // closing may need signed evidence: the ticket then stays Work Completed
            }
        }

        return $t;
    }

    /** Spread the tickets over the last 60 days (reports and sorting look real). Quiet: no events, no history change. */
    private function backdate(MaintenanceRequest $t): void
    {
        $when = now()->subDays(mt_rand(0, 60))->setTime(mt_rand(7, 16), mt_rand(0, 59));

        $t->forceFill(['submitted_at' => $when, 'created_at' => $when])->saveQuietly();
    }

    private function user(string $slug): ?User
    {
        return User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', $slug))->first();
    }

    /** Your real requesters; if there are fewer than 4, a few students and teachers are made. @return list<User> */
    private function requesters(): array
    {
        $list = User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', Role::REQUESTER))->get()->all();

        if (count($list) >= 4) {
            return $list;
        }

        $role = Role::where('slug', Role::REQUESTER)->firstOrFail();
        foreach (['Ana Dizon', 'Ben Lopez', 'Carla Reyes', 'Dan Mercado', 'Ella Cruz', 'Femi Santos'] as $n => $name) {
            $list[] = User::updateOrCreate(['email' => 'testdata'.($n + 1).'@lcctanauan.edu.ph'], [
                'name' => $name, 'password' => Hash::make('password'), 'role_id' => $role->id,
                'department_id' => Department::query()->inRandomOrder()->value('id'), 'is_active' => true, 'email_verified_at' => now(),
            ]);
        }

        return $list;
    }

    /** Active maintenance staff; if there are none, 3 are made. @return list<User> */
    private function maintenanceStaff(User $head): array
    {
        $staff = User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', Role::STAFF))
            ->where('department_id', $head->department_id)->get()->all();

        if ($staff !== []) {
            return $staff;
        }

        $role = Role::where('slug', Role::STAFF)->firstOrFail();
        foreach (['Pedro Cruz', 'Luis Ramos', 'Jun Bautista'] as $n => $name) {
            $staff[] = User::updateOrCreate(['email' => 'teststaff'.($n + 1).'@lcctanauan.edu.ph'], [
                'name' => $name, 'password' => Hash::make('password'), 'role_id' => $role->id,
                'department_id' => $head->department_id, 'is_active' => true, 'email_verified_at' => now(),
            ]);
        }

        return $staff;
    }
}
