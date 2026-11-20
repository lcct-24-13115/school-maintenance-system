<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Clearance;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\EmailLog;
use App\Models\FacilityRequest;
use App\Models\FacilityRequestItem;
use App\Models\Facility;
use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\FaqSearchMiss;
use App\Models\Inventory;
use App\Models\InventoryCheck;
use App\Models\MaintenanceRequest;
use App\Models\QrCode;
use App\Models\ReceiptExpenditure;
use App\Models\RequisitionSlip;
use App\Models\RequisitionSlipItem;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * TEST DATA FOR EVERYTHING (100+ rows where it makes sense). For testing only - delete before going live.
 *
 *   php artisan db:seed --class=TestDataSeeder
 *
 * What it fills (counts can be changed with env vars, e.g. SEED_TICKETS=300):
 *   users (teachers, heads, staff, 130 students, other requesters, pending/rejected registrations),
 *   department heads + descriptions + budgets, subcategories, 150 tickets (through the REAL workflow, so the approval
 *   chain, history and assignment rules are real) + conversation replies + internal notes, 100 Use-of-Facilities forms,
 *   100 Requisition Slips, inventory + year-end checks + clearances, receipts, announcements, QR codes, FAQ + votes +
 *   missed searches, notifications, e-mail log and audit log.
 *
 * Every login uses the password "password". No real e-mail is sent. Safe to run again: each section skips itself
 * when its test rows are already there. To start clean: php artisan migrate:fresh --seed
 *
 * Needs the base seeders first (php artisan db:seed): roles, departments, categories, facilities, the 6 staff accounts.
 */
class TestDataSeeder extends Seeder
{
    private const FIRST = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Carla', 'Miguel', 'Sofia', 'Luis', 'Isabel', 'Rafael', 'Elena', 'Carlo', 'Patricia',
        'Mark', 'Angelica', 'John', 'Kristine', 'Paolo', 'Jasmine', 'Gabriel', 'Bianca', 'Nathan', 'Camille', 'Joshua', 'Danica',
        'Ryan', 'Trisha', 'Kevin', 'Hannah', 'Jericho', 'Mika', 'Adrian', 'Lyka', 'Dominic', 'Faith', 'Enzo', 'Shaira', 'Renz', 'Aira',
    ];

    private const LAST = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Bautista', 'Ocampo', 'Mendoza', 'Torres', 'Flores', 'Villanueva', 'Ramos', 'Aquino',
        'Castillo', 'Rivera', 'Navarro', 'Salazar', 'Pascual', 'Tolentino', 'Manalo', 'Macaraig', 'De Guzman', 'Lopez', 'Fernandez',
        'Gonzales', 'Cruz', 'Alvarez', 'Perez', 'Domingo', 'Magsaysay', 'Panganiban', 'Evangelista', 'Mercado', 'Soriano', 'Valdez',
        'Hernandez', 'Marasigan', 'Dimaculangan', 'Maralit', 'Atienza', 'Bondoc',
    ];

    private const PROGRAMS = ['BSIT 1st year', 'BSIT 2nd year', 'BSIT 3rd year', 'BSBA 2nd year', 'BSED 1st year', 'BSED 4th year', 'BSN 2nd year', 'BSHM 3rd year', 'Grade 10', 'Grade 11 STEM', 'Grade 12 ABM', 'Grade 8'];

    private const PLACES = [
        'Grade 5 Room 204', 'Grade 6 Comfort Room', 'Grade 4 Room 101', 'Library entrance', 'Computer Laboratory', 'Science Laboratory',
        'Faculty Room', 'Guidance Office', 'Canteen', 'Main Gate', 'Gymnasium', 'Registrar Office', 'Chapel', 'Cashier Window',
        'Admin Building 2nd floor', 'Covered Court', 'Quadrangle', 'Conference Room', 'HE Laboratory', 'College Library',
    ];

    private const PROBLEMS = [
        'Faucet keeps leaking after the washer was replaced.', 'Ceiling fan makes a loud noise and wobbles.', 'Two fluorescent lights are not working.',
        'Door lock is jammed, the room cannot be locked.', 'Window glass is cracked.', 'Aircon is not cooling.', 'Clogged sink in the comfort room.',
        'Electrical outlet sparks when plugged in.', 'Broken student armchairs, 5 pieces.', 'Whiteboard is falling off the wall.',
        'Water pump is not delivering water to the second floor.', 'Roof leaks when it rains.', 'Projector will not turn on.',
        'Paint is peeling on the corridor wall.', 'Gate hinge is broken.', 'Toilet does not flush.', 'Circuit breaker keeps tripping.',
        'Cabinet door fell off its hinges.', 'Floor tiles are loose near the entrance.', 'Exhaust fan is not working.',
    ];

    private const ITEMS = [
        ['PVC pipe 1/2"', 'pcs'], ['Faucet', 'pcs'], ['LED tube light 18W', 'pcs'], ['Electrical tape', 'rolls'], ['Door knob set', 'sets'],
        ['Padlock', 'pcs'], ['Paint (white, 4L)', 'cans'], ['Plywood 1/2"', 'sheets'], ['Cement', 'bags'], ['Wire #12', 'meters'],
        ['Circuit breaker 20A', 'pcs'], ['Ceiling fan', 'units'], ['Hinges', 'pairs'], ['Silicone sealant', 'tubes'], ['Floor tiles', 'boxes'],
    ];

    private string $hash;

    /** @var list<User> */
    private array $teachers = [];

    /** @var list<User> */
    private array $students = [];

    /** @var list<User> */
    private array $staff = [];

    /** @var array<string, User> department code => head */
    private array $heads = [];

    /** @var list<Department> */
    private array $depts = [];   // every department except the Maintenance Office

    private ?User $custodian = null;

    private ?User $vp = null;

    private ?User $finance = null;

    private ?User $president = null;

    private ?User $mHead = null;

    private ?User $sysadmin = null;

    private int $nameCounter = 0;

    public function run(): void
    {
        config(['school.ticket_emails' => false]);
        Notification::fake();   // no in-app alerts / e-mails are sent while the workflow runs
        mt_srand(2026);         // the same test data every run
        $this->hash = Hash::make('password');

        $this->custodian = $this->userByRole(Role::CUSTODIAN);
        $this->vp = $this->userByRole(Role::VICE_PRINCIPAL);
        $this->finance = $this->userByRole(Role::FINANCE);
        $this->president = $this->userByRole(Role::PRESIDENT);
        $this->mHead = $this->userByRole(Role::MAINTENANCE_ADMIN);
        $this->sysadmin = $this->userByRole(Role::SYSTEM_ADMIN);

        if (! $this->custodian || ! $this->vp || ! $this->finance || ! $this->president || ! $this->mHead
            || Department::query()->count() < 2 || Category::query()->count() < 1 || Facility::query()->count() < 1) {
            $this->command?->warn('Run the base seeders first:  php artisan db:seed   (roles, departments, categories, facilities, staff accounts).');

            return;
        }

        foreach ([
            'users' => fn () => $this->users(),
            'departments' => fn () => $this->departments(),
            'subcategories' => fn () => $this->subcategories(),
            'tickets' => fn () => $this->tickets(),
            'ticket conversation' => fn () => $this->conversations(),
            'facility forms' => fn () => $this->facilityForms(),
            'requisition slips' => fn () => $this->requisitionSlips(),
            'inventory' => fn () => $this->inventory(),
            'receipts' => fn () => $this->receipts(),
            'announcements' => fn () => $this->announcements(),
            'qr codes' => fn () => $this->qrCodes(),
            'faq' => fn () => $this->faq(),
            'notifications' => fn () => $this->notifications(),
            'email log' => fn () => $this->emailLog(),
            'audit log' => fn () => $this->auditLog(),
        ] as $label => $section) {
            try {
                $count = $section();
                $this->command?->info(sprintf('%-20s %s', $label, $count === null ? 'skipped (already there)' : $count.' rows'));
            } catch (Throwable $e) {
                $this->command?->warn("{$label}: stopped - ".$e->getMessage());
            }
        }

        $this->command?->info('Test data done. Password of every test account: "password".');
    }

    // =================================================================================================
    // USERS
    // =================================================================================================

    private function users(): ?int
    {
        $made = 0;
        $this->depts = Department::query()->where('code', '!=', Department::MAINTENANCE_CODE)->orderBy('id')->get()->all();
        $mnt = Department::maintenanceId();

        // 14 teachers (advisers)
        for ($i = 1; $i <= 14; $i++) {
            $this->teachers[] = $this->makeUser(Role::TEACHER, "test.teacher{$i}@lcctanauan.edu.ph", $this->depts[$i % count($this->depts)]->id, "TEST-T{$i}");
            $made++;
        }

        // one Department Head per office (they sign the forms of their own office)
        foreach ($this->depts as $d) {
            $head = $this->makeUser(Role::HEAD, 'test.head.'.strtolower($d->code).'@lcctanauan.edu.ph', $d->id, 'TEST-H'.$d->code);
            $this->heads[$d->code] = $head;
            $d->update(['head_user_id' => $head->id]);
            $made++;
        }

        // 10 maintenance staff (2 of them "staff only": cannot assign others)
        for ($i = 1; $i <= 10; $i++) {
            $u = $this->makeUser(Role::STAFF, "test.staff{$i}@lcctanauan.edu.ph", $mnt, "TEST-M{$i}");
            if ($i > 8 && Schema::hasColumn('users', 'staff_only')) {
                $u->forceFill(['staff_only' => true])->save();
            }
            $this->staff[] = $u;
            $made++;
        }

        // 130 students, each with an adviser
        for ($i = 1; $i <= 130; $i++) {
            $u = $this->makeUser(Role::REQUESTER, '26-'.(10000 + $i).'@lcctanauan.edu.ph', $this->depts[mt_rand(0, count($this->depts) - 1)]->id, sprintf('TEST-S%04d', $i), [
                'requester_type' => 'STUDENT',
                'affiliation' => self::PROGRAMS[mt_rand(0, count(self::PROGRAMS) - 1)],
                'reg_purpose' => 'TEST DATA - report broken facilities.',
                'adviser_id' => $this->teachers[$i % count($this->teachers)]->id,
            ]);
            $this->students[] = $u;
            $made++;
        }

        // 15 faculty / staff requesters (school e-mail)
        for ($i = 1; $i <= 15; $i++) {
            $this->makeUser(Role::REQUESTER, "test.faculty{$i}@lcctanauan.edu.ph", $this->depts[mt_rand(0, count($this->depts) - 1)]->id, "TEST-F{$i}", [
                'requester_type' => 'FACULTY/STAFF', 'affiliation' => $this->depts[mt_rand(0, count($this->depts) - 1)]->name,
            ]);
            $made++;
        }

        // 12 parents / guardians (outside e-mail): 6 waiting for approval, 3 rejected, 3 approved
        foreach (range(1, 12) as $i) {
            $extra = ['requester_type' => 'PARENT/GUARDIAN', 'affiliation' => $this->name().', '.self::PROGRAMS[mt_rand(0, count(self::PROGRAMS) - 1)], 'reg_purpose' => 'TEST DATA - I want to report a problem for my child.'];
            if ($i <= 6) {
                $extra += ['approval_status' => User::STATUS_PENDING];
            } elseif ($i <= 9) {
                $extra += ['approval_status' => User::STATUS_REJECTED, 'rejection_reason' => 'Could not confirm the student.', 'decided_by' => $this->mHead->id, 'decided_at' => now()->subDays(mt_rand(1, 20))];
            }
            $this->makeUser(Role::REQUESTER, "test.parent{$i}@gmail.com", null, "TEST-P{$i}", $extra, active: $i > 9);
            $made++;
        }

        // 8 pending registrations of students / faculty (waiting for the admin)
        for ($i = 1; $i <= 8; $i++) {
            $this->makeUser(Role::REQUESTER, "test.pending{$i}@lcctanauan.edu.ph", $this->depts[mt_rand(0, count($this->depts) - 1)]->id, "TEST-W{$i}", [
                'requester_type' => $i % 2 ? 'STUDENT' : 'FACULTY/STAFF', 'affiliation' => self::PROGRAMS[mt_rand(0, count(self::PROGRAMS) - 1)],
                'reg_purpose' => 'TEST DATA - new registration.', 'approval_status' => User::STATUS_PENDING,
            ], active: false);
            $made++;
        }

        // 6 deactivated accounts (to test the "inactive" filter)
        foreach (array_slice($this->students, 0, 6) as $u) {
            $u->forceFill(['is_active' => false])->saveQuietly();
        }

        return $made;
    }

    private function loadPeople(): void
    {
        $this->depts = Department::query()->where('code', '!=', Department::MAINTENANCE_CODE)->orderBy('id')->get()->all();
        $by = fn (string $slug) => User::query()->where('employee_no', 'like', 'TEST-%')->where('is_active', true)->where('approval_status', 'approved')
            ->whereHas('role', fn ($q) => $q->where('slug', $slug))->get()->all();

        $this->teachers = $by(Role::TEACHER);
        $this->staff = $by(Role::STAFF);
        $this->students = array_values(array_filter($by(Role::REQUESTER), fn (User $u) => $u->requester_type === 'STUDENT'));

        foreach ($by(Role::HEAD) as $h) {
            $code = Department::query()->whereKey($h->department_id)->value('code');
            if ($code) {
                $this->heads[$code] = $h;
            }
        }
    }

    private function makeUser(string $slug, string $email, ?int $departmentId, string $employeeNo, array $extra = [], bool $active = true): User
    {
        $user = User::query()->where('email', $email)->first() ?? new User;
        $values = [
            'role_id' => Role::where('slug', $slug)->value('id'),
            'department_id' => $departmentId,
            'employee_no' => $employeeNo,
            'name' => $this->name(),
            'email' => $email,
            'password' => $this->hash,
            'is_active' => $active,
            'email_verified_at' => now(),
            'created_at' => now()->subDays(mt_rand(0, 200)),
        ] + $extra;
        $user->forceFill(array_filter($values, fn ($v, $col) => Schema::hasColumn('users', $col), ARRAY_FILTER_USE_BOTH))->save();

        return $user;
    }

    private function name(): string
    {
        $this->nameCounter++;

        return self::FIRST[$this->nameCounter % count(self::FIRST)].' '.self::LAST[(int) floor($this->nameCounter / 3 + mt_rand(0, 9)) % count(self::LAST)];
    }

    // =================================================================================================
    // DEPARTMENTS, BUDGETS, SUBCATEGORIES
    // =================================================================================================

    private function departments(): ?int
    {
        $n = 0;
        foreach (Department::query()->orderBy('id')->get() as $d) {
            if (blank($d->description)) {
                $d->update(['description' => "{$d->name} - handles requests and concerns of the school community. (TEST DATA)"]);
            }
            foreach (range(2022, 2030) as $year) {
                DepartmentBudget::query()->updateOrCreate(
                    ['department_id' => $d->id, 'fiscal_year' => $year],
                    ['allocated_amount' => mt_rand(8, 60) * 10000, 'alert_threshold_percent' => [70, 80, 90][mt_rand(0, 2)]],
                );
                $n++;
            }
        }

        return $n;
    }

    private function subcategories(): ?int
    {
        if (Subcategory::query()->where('description', 'TEST DATA')->exists()) {
            return null;
        }

        $made = 0;
        $words = ['Minor repair', 'Major repair', 'Replacement', 'Inspection', 'Cleaning', 'Installation', 'Relocation', 'Safety concern', 'Urgent fix', 'Follow-up'];

        foreach (Category::query()->where('is_active', true)->get() as $category) {
            foreach (array_slice($words, 0, mt_rand(3, 5)) as $i => $word) {
                $name = Str::limit("{$word} - {$category->name}", 100, '');
                $sub = $category->subcategories()->firstOrCreate(['name' => $name], ['sort_order' => 50 + $i, 'description' => 'TEST DATA', 'is_hidden' => $i === 4 && mt_rand(0, 3) === 0]);
                $made += $sub->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $made;
    }

    // =================================================================================================
    // TICKETS (real workflow)
    // =================================================================================================

    private function tickets(): ?int
    {
        $existing = MaintenanceRequest::query()->where('reason', 'TEST DATA')->count();
        $total = (int) env('SEED_TICKETS', 150) - $existing;
        if ($total <= 0) {
            return null;
        }
        if ($this->students === [] || $this->teachers === []) {
            throw new \RuntimeException('No students / teachers yet: the users section failed. Fix that first.');
        }

        $mix = [
            'waiting_adviser' => 12, 'waiting_vp' => 10, 'waiting_finance' => 8, 'waiting_president' => 8, 'disapproved' => 10, 'cancelled' => 5,
            'approved' => 12, 'assigned' => 12, 'in_progress' => 12, 'waiting_user' => 5, 'completed' => 8, 'closed' => 8,
        ];
        $plan = [];
        foreach ($mix as $state => $n) {
            $plan = array_merge($plan, array_fill(0, $n, $state));
        }
        shuffle($plan);

        $categories = Category::query()->where('is_active', true)->where('department_id', Department::maintenanceId())->get();
        if ($categories->isEmpty()) {
            $categories = Category::query()->where('is_active', true)->get();
        }
        $requesters = array_merge($this->students, $this->teachers, User::query()->where('employee_no', 'like', 'TEST-F%')->get()->all());
        $made = 0;

        for ($i = 0; $i < $total; $i++) {
            $state = $plan[$i % count($plan)];
            $isStudentState = $state === 'waiting_adviser';
            $pool = array_values(array_filter($isStudentState ? $this->students : $requesters, fn (User $u) => $u->is_active));
            $requester = $pool[$i % count($pool)];
            $category = $categories[mt_rand(0, $categories->count() - 1)];
            $sub = $category->subcategories()->inRandomOrder()->first();
            $type = $category->type !== Category::TYPE_SERVICE ? $category->type : null;
            $items = [];
            if ($type !== null && mt_rand(0, 2) === 0) {
                foreach (range(1, mt_rand(1, 3)) as $_) {
                    $it = self::ITEMS[mt_rand(0, count(self::ITEMS) - 1)];
                    $items[] = ['item_name' => $it[0], 'quantity' => mt_rand(1, 12), 'unit' => $it[1], 'specification' => mt_rand(0, 1) ? 'Same as the old one.' : null];
                }
            }

            try {
                $ticket = TicketWorkflow::open($requester, [
                    'department_id' => $requester->department_id ?? $this->depts[0]->id,
                    'category_id' => $category->id,
                    'subcategory_id' => $sub?->id,
                    'request_type' => $type,
                    'maintenance_type' => ['repair', 'replacement', 'installation', 'improvement'][mt_rand(0, 3)],
                    'location' => self::PLACES[mt_rand(0, count(self::PLACES) - 1)],
                    'description' => self::PROBLEMS[mt_rand(0, count(self::PROBLEMS) - 1)],
                    'reason' => 'TEST DATA',
                    'needed_by' => now()->addDays(mt_rand(5, 40))->toDateString(),
                    'is_confidential' => $i % 33 === 7,
                ], null, $items);

                $ticket = $this->advance($ticket, $state, $requester);
                $when = now()->subDays(mt_rand(0, 90))->setTime(mt_rand(7, 16), mt_rand(0, 59));
                $ticket->forceFill(['submitted_at' => $when, 'created_at' => $when])->saveQuietly();
                $made++;
            } catch (Throwable $e) {
                $this->command?->warn("Ticket {$i} ({$state}) stopped: ".$e->getMessage());
            }
        }

        return $made;
    }

    private function advance(MaintenanceRequest $t, string $state, User $requester): MaintenanceRequest
    {
        $signer = [Role::VICE_PRINCIPAL => $this->vp, Role::FINANCE => $this->finance, Role::PRESIDENT => $this->president];

        /** Sign the next $steps lines of the chain, whoever they belong to (an adviser's step belongs to ONE teacher). */
        $sign = function (int $steps) use (&$t, $signer) {
            for ($k = 0; $k < $steps; $k++) {
                $line = $t->approvals()->where('status', 'pending')->first();
                if ($line === null) {
                    return;
                }
                $by = $line->role_slug === 'adviser' ? User::find($line->approver_id) : ($signer[$line->role_slug] ?? null);
                $t = TicketWorkflow::authorize($t, $by, 'Approved (test data).', [], $line->role_slug === Role::FINANCE ? mt_rand(0, 5000) : null);
            }
        };

        // how many lines of the chain does this ticket have? (a student's has the adviser line first)
        $lines = $t->approvals()->count();
        $adviser = $t->approvals()->where('step', 'adviser')->exists() ? 1 : 0;
        $staff = $this->staff[mt_rand(0, count($this->staff) - 1)];
        $date = now()->addDays(mt_rand(-3, 14))->toDateString();

        switch ($state) {
            case 'waiting_adviser':
            case 'waiting_vp':
                return $t;
            case 'waiting_finance':
                $sign($adviser + 1);

                return $t;
            case 'waiting_president':
                $sign($adviser + 2);

                return $t;
            case 'disapproved':
                $sign(mt_rand(0, $lines - 1));
                $line = $t->approvals()->where('status', 'pending')->first();
                $by = $line->role_slug === 'adviser' ? User::find($line->approver_id) : $signer[$line->role_slug];

                return TicketWorkflow::disapprove($t, $by, 'Not needed at this time.');
            case 'cancelled':
                return TicketWorkflow::cancel($t, $requester, 'Already fixed by someone else.');
        }

        $sign($lines);   // everything below is fully approved

        if ($state === 'approved') {
            return $t;
        }

        $t = TicketWorkflow::assign($t, $staff, $this->mHead, 'Test data.', $date);

        return match ($state) {
            'assigned' => $t,
            'in_progress' => TicketWorkflow::startWork($t, $staff),
            'waiting_user' => TicketWorkflow::requestInfo($t, $this->mHead, 'Which room exactly, and who can open it for us?'),
            'completed', 'closed' => $this->finish($t, $staff, $state === 'closed'),
            default => $t,
        };
    }

    private function finish(MaintenanceRequest $t, User $staff, bool $close): MaintenanceRequest
    {
        $t = TicketWorkflow::startWork($t, $staff);
        $t = TicketWorkflow::markWorkCompleted($t, $staff, mt_rand(0, 2) === 0 ? 'Spare part had to be ordered.' : null, mt_rand(0, 2) === 0 ? 'Check this again next semester.' : null);

        if ($close) {
            try {
                $t = TicketWorkflow::close($t, $this->mHead);
            } catch (Throwable) {
                // closing may need signed evidence: the ticket then stays "Work Completed"
            }
        }

        return $t;
    }

    /** Messages and internal notes on the test tickets (about 250 rows). */
    private function conversations(): ?int
    {
        $tickets = MaintenanceRequest::query()->where('reason', 'TEST DATA')->whereDoesntHave('replies')->get();
        if ($tickets->isEmpty()) {
            return null;
        }

        $fromRequester = ['Good day! Any update on this?', 'The problem is getting worse, please check when you can.', 'Thank you for the quick response.', 'The room is on the 2nd floor, near the stairs.', 'Okay, noted. We will leave the door open.'];
        $fromStaff = ['We will check it tomorrow morning.', 'Materials are being prepared.', 'Scheduled for this week.', 'Please keep the area clear while we work.', 'Done checking, we need one more part.'];
        $notes = ['Ask the supplier for the price first.', 'Needs a ladder and two people.', 'Same problem as last month in this room.', 'Coordinate with the Guidance Office before starting.'];
        $made = 0;

        foreach ($tickets as $t) {
            if ($t->status->isTerminal()) {
                continue;
            }
            $by = $t->requester;
            $staff = $t->assignee ?? $this->mHead;

            foreach (range(1, mt_rand(1, 3)) as $n) {
                try {
                    TicketWorkflow::addReply($t, $n % 2 ? $by : $staff, $n % 2 ? $fromRequester[mt_rand(0, 4)] : $fromStaff[mt_rand(0, 4)]);
                    $made++;
                } catch (Throwable) {
                    // some states do not allow a reply from this person: skip it
                }
            }
            if (mt_rand(0, 2) === 0) {
                try {
                    TicketWorkflow::addReply($t, $this->mHead, $notes[mt_rand(0, 3)], [], true);
                    $made++;
                } catch (Throwable) {
                }
            }
        }

        return $made;
    }

    // =================================================================================================
    // FORM 1: USE OF FACILITIES & EQUIPMENT  (100)
    // =================================================================================================

    private function facilityForms(): ?int
    {
        if (FacilityRequest::query()->where('purpose', 'like', 'TEST DATA%')->exists()) {
            return null;
        }

        $purposes = ['Class program rehearsal', 'Seminar for faculty', 'Student council meeting', 'Intramurals practice', 'Parent orientation', 'Department meeting', 'Thesis defense', 'Foundation Day preparation', 'Prayer service', 'Cooking demonstration'];
        $equipment = ['Microphone', 'Projector', 'Extension cord', 'Sound system', 'Monobloc chairs', 'Folding tables', 'Laptop', 'HDMI cable', 'Whiteboard marker set', 'Electric fan'];
        $facilityIds = Facility::query()->pluck('id')->all();
        $requesters = array_merge($this->teachers, User::query()->where('employee_no', 'like', 'TEST-F%')->get()->all());
        $made = 0;

        foreach (range(1, (int) env('SEED_FORMS', 100)) as $i) {
            $requester = $requesters[$i % count($requesters)];
            $filed = now()->subDays(mt_rand(0, 90));

            $form = FacilityRequest::create([
                'requester_id' => $requester->id,
                'department_id' => $requester->department_id,
                'date_filed' => $filed->toDateString(),
                'purpose' => 'TEST DATA - '.$purposes[mt_rand(0, count($purposes) - 1)],
                'persons_count' => mt_rand(5, 150),
                'date_needed' => $filed->copy()->addDays(mt_rand(3, 30))->toDateString(),
                'time_needed' => sprintf('%02d:%02d:00', mt_rand(7, 16), [0, 30][mt_rand(0, 1)]),
                'other_facility' => mt_rand(0, 9) === 0 ? 'Open field' : null,
                'status' => 'submitted',
            ]);

            $form->facilities()->sync(array_unique([$facilityIds[mt_rand(0, count($facilityIds) - 1)], $facilityIds[mt_rand(0, count($facilityIds) - 1)]]));
            foreach (range(1, mt_rand(1, 3)) as $_) {
                FacilityRequestItem::create(['fe_request_id' => $form->id, 'quantity' => mt_rand(1, 40), 'description' => $equipment[mt_rand(0, count($equipment) - 1)]]);
            }

            try {
                $this->signFacility($form, $requester, $i);
            } catch (Throwable $e) {
                $this->command?->warn("Facility form {$i} stopped: ".$e->getMessage());
            }
            $form->forceFill(['created_at' => $filed])->saveQuietly();
            $made++;
        }

        return $made;
    }

    private function signFacility(FacilityRequest $form, User $requester, int $i): void
    {
        $head = $this->heads[Department::query()->whereKey($form->department_id)->value('code')] ?? null;
        $stage = $i % 8;   // 0 new, 1 endorsed, 2 noted, 3 approved, 4 returned, 5 closed, 6 rejected by custodian, 7 rejected by president

        if ($stage === 0 || $head === null) {
            return;
        }
        $form->sign($head, 'Endorsed. (test data)');
        if ($stage === 1) {
            return;
        }
        if ($stage === 6) {
            $form->refresh()->reject($this->custodian, 'Facility is already booked on that date.');

            return;
        }
        $form->refresh()->sign($this->custodian, 'Noted.');
        if ($stage === 2) {
            return;
        }
        if ($stage === 7) {
            $form->refresh()->reject($this->president, 'Not approved this time.');

            return;
        }
        $form->refresh()->sign($this->president, 'Approved.');
        if ($stage === 3) {
            return;
        }
        $form->refresh()->markReturned($this->custodian, $requester->name, now()->subDays(mt_rand(0, 10)));
        if ($stage === 5) {
            $form->refresh()->close();
        }
    }

    // =================================================================================================
    // FORM 2: REQUISITION SLIPS  (100)
    // =================================================================================================

    private function requisitionSlips(): ?int
    {
        if (RequisitionSlip::query()->where('remarks', 'like', 'TEST DATA%')->exists()) {
            return null;
        }

        $things = [['Bond paper (long)', 'Office supplies', 280], ['Whiteboard markers', 'Office supplies', 150], ['Printer ink set', 'Office supplies', 1200], ['Extension cords', 'Equipment', 450],
            ['Electric fans', 'Equipment', 1800], ['Cleaning chemicals', 'Janitorial supplies', 600], ['Mop and bucket set', 'Janitorial supplies', 520], ['Folders', 'Office supplies', 90],
            ['Door lock repair', 'Repairs and maintenance', 950], ['Aircon cleaning', 'Repairs and maintenance', 1500], ['Snacks for seminar', 'Meetings and seminars', 3500], ['Tarpaulin printing', 'Marketing', 1400]];
        $requesters = array_merge($this->teachers, User::query()->where('employee_no', 'like', 'TEST-F%')->get()->all());
        $types = array_keys(RequisitionSlip::TYPES);
        $made = 0;

        foreach (range(1, (int) env('SEED_FORMS', 100)) as $i) {
            $requester = $requesters[$i % count($requesters)];
            $filed = now()->subDays(mt_rand(0, 90));
            $picked = array_values(array_unique([$types[mt_rand(0, 4)], $types[mt_rand(0, 3)]]));

            $slip = RequisitionSlip::create([
                'requester_id' => $requester->id,
                'department_id' => $requester->department_id,
                'date_filed' => $filed->toDateString(),
                'date_needed' => $filed->copy()->addDays(mt_rand(4, 30))->toDateString(),
                'request_types' => $picked,
                'other_type' => in_array('others', $picked, true) ? 'Miscellaneous' : null,
                'payee_name' => in_array('cash', $picked, true) ? $this->name() : null,
                'remarks' => 'TEST DATA - needed for the coming activity.',
                'status' => 'submitted',
            ]);

            foreach (range(1, mt_rand(1, 4)) as $_) {
                $t = $things[mt_rand(0, count($things) - 1)];
                $qty = mt_rand(1, 10);
                RequisitionSlipItem::create(['requisition_slip_id' => $slip->id, 'quantity' => $qty, 'description' => $t[0], 'account_charged' => $t[1], 'amount' => $qty * $t[2]]);
            }
            $slip->recalculateTotal();

            try {
                $this->signSlip($slip, $i);
            } catch (Throwable $e) {
                $this->command?->warn("Requisition {$i} stopped: ".$e->getMessage());
            }
            $slip->forceFill(['created_at' => $filed])->saveQuietly();
            $made++;
        }

        return $made;
    }

    private function signSlip(RequisitionSlip $slip, int $i): void
    {
        $head = $this->heads[Department::query()->whereKey($slip->department_id)->value('code')] ?? null;
        $stage = $i % 7;   // 0 new, 1 noted, 2 recommended, 3 approved, 4 closed, 5 rejected by finance, 6 rejected by president

        if ($stage === 0 || $head === null) {
            return;
        }
        $slip->sign($head, 'Noted. (test data)');
        if ($stage === 1) {
            return;
        }
        if ($stage === 5) {
            $slip->refresh()->reject($this->finance, 'Not in the budget.');

            return;
        }
        $slip->refresh()->sign($this->finance, 'Funds available.');
        if ($stage === 2) {
            return;
        }
        if ($stage === 6) {
            $slip->refresh()->reject($this->president, 'Please reduce the quantity.');

            return;
        }
        $slip->refresh()->sign($this->president, 'Approved.');
        if ($stage === 4) {
            $slip->refresh()->close();
        }
    }

    // =================================================================================================
    // INVENTORY, YEAR-END CHECKS, CLEARANCE  (120 + checks + clearances)
    // =================================================================================================

    private function inventory(): ?int
    {
        if (Inventory::query()->where('property_tag', 'like', 'TEST-%')->exists()) {
            return null;
        }

        $things = ['Desktop computer', 'Monitor', 'Printer', 'Projector', 'Aircon unit', 'Electric fan', 'Student armchair', 'Teacher table', 'Steel cabinet', 'Whiteboard', 'Water dispenser',
            'Sound system', 'Laptop', 'Photocopier', 'UPS', 'Wall clock', 'Microscope', 'Office chair', 'Filing cabinet', 'Podium'];
        $conditions = ['good', 'good', 'good', 'fair', 'fair', 'needs_repair', 'unserviceable', 'disposed'];
        $categoryIds = Category::query()->pluck('id')->all();
        $deptIds = Department::query()->pluck('id')->all();
        $owners = array_merge($this->teachers, $this->staff);
        $made = 0;

        foreach (range(1, (int) env('SEED_INVENTORY', 120)) as $i) {
            $item = Inventory::create([
                'property_tag' => sprintf('TEST-%s-%04d', date('Y'), $i),
                'name' => $things[$i % count($things)].' #'.$i,
                'category_id' => $categoryIds[mt_rand(0, count($categoryIds) - 1)],
                'department_id' => $deptIds[mt_rand(0, count($deptIds) - 1)],
                'assigned_user_id' => $owners[mt_rand(0, count($owners) - 1)]->id,
                'custodian_id' => $this->custodian->id,
                'condition' => $conditions[mt_rand(0, count($conditions) - 1)],
                'location' => self::PLACES[mt_rand(0, count(self::PLACES) - 1)],
                'acquired_at' => now()->subDays(mt_rand(30, 2000))->toDateString(),
                'last_checked_at' => now()->subDays(mt_rand(0, 300)),
            ]);

            // year-end checks for the last 2 years
            foreach ([(int) date('Y') - 1, (int) date('Y')] as $year) {
                if (mt_rand(0, 4) === 0) {
                    continue;
                }
                InventoryCheck::create([
                    'inventory_id' => $item->id, 'checked_by' => $this->custodian->id, 'check_year' => $year,
                    'result' => ['found', 'found', 'found', 'missing', 'damaged', 'transferred'][mt_rand(0, 5)],
                    'remarks' => mt_rand(0, 2) === 0 ? 'TEST DATA - checked during the year-end count.' : null,
                    'checked_at' => now()->subDays(mt_rand(0, 300)),
                ]);
            }
            $made++;
        }

        // personnel clearance: one per teacher / staff
        foreach (array_merge($this->teachers, $this->staff) as $i => $person) {
            $status = ['pending', 'cleared', 'cleared', 'with_accountability'][$i % 4];
            Clearance::create([
                'user_id' => $person->id,
                'verified_by' => $status === 'pending' ? null : $this->custodian->id,
                'status' => $status,
                'remarks' => $status === 'with_accountability' ? 'TEST DATA - 1 item not yet returned.' : null,
                'verified_at' => $status === 'pending' ? null : now()->subDays(mt_rand(0, 60)),
            ]);
            $made++;
        }

        return $made;
    }

    // =================================================================================================
    // RECEIPTS & EXPENDITURES (Finance, after purchase)
    // =================================================================================================

    private function receipts(): ?int
    {
        if (ReceiptExpenditure::query()->where('or_number', 'like', 'TEST-OR-%')->exists()) {
            return null;
        }

        $tickets = MaintenanceRequest::query()->where('reason', 'TEST DATA')->whereNotNull('president_approved_at')->get();
        if ($tickets->isEmpty()) {
            return 0;
        }
        $suppliers = ['Wilcon Depot', 'Ace Hardware', 'CitiHardware', 'Local Hardware', 'Electro Mart', 'Mang Tomas Supply', 'School & Office World'];
        $made = 0;

        foreach (range(1, 110) as $i) {
            $t = $tickets[$i % $tickets->count()];
            ReceiptExpenditure::create([
                'request_id' => $t->id,
                'department_id' => $t->department_id,
                'recorded_by' => $this->finance->id,
                'or_number' => sprintf('TEST-OR-%05d', $i),
                'supplier' => $suppliers[mt_rand(0, count($suppliers) - 1)],
                'purchase_date' => now()->subDays(mt_rand(0, 120))->toDateString(),
                'amount' => mt_rand(150, 25000) + mt_rand(0, 99) / 100,
                'receipt_path' => 'test-data/receipt-'.$i.'.pdf',   // no real file: only the row is for testing
                'remarks' => mt_rand(0, 3) === 0 ? 'TEST DATA - paid in cash.' : null,
            ]);
            $made++;
        }

        return $made;
    }

    // =================================================================================================
    // ANNOUNCEMENTS, QR CODES
    // =================================================================================================

    private function announcements(): ?int
    {
        if (Announcement::query()->where('title', 'like', 'TEST%')->exists()) {
            return null;
        }

        $topics = ['Water interruption', 'Aircon cleaning schedule', 'Comfort room repair', 'Power outage notice', 'Pest control schedule', 'Roof repair work', 'Canteen closed for repair',
            'Gym floor repainting', 'Library renovation', 'Fire drill', 'Campus cleanup day', 'New ticketing system', 'Parking area repair', 'Wi-Fi maintenance', 'Elevator inspection'];
        $made = 0;

        foreach (range(1, 105) as $i) {
            $published = $i % 5 !== 0;
            $at = now()->subDays(mt_rand(0, 120));
            Announcement::create([
                'author_id' => $this->mHead->id,
                'title' => 'TEST '.$topics[$i % count($topics)].' #'.$i,
                'body' => 'TEST DATA. Please expect some noise and limited access to the area. We apologize for the inconvenience and thank you for your patience.',
                'is_published' => $published,
                'published_at' => $published ? $at : null,
                'expires_at' => $published && $i % 4 === 0 ? $at->copy()->addDays(mt_rand(3, 30)) : null,
            ]);
            $made++;
        }

        return $made;
    }

    private function qrCodes(): ?int
    {
        if (QrCode::query()->where('title', 'like', 'TEST%')->exists()) {
            return null;
        }

        $colors = ['#1b3355', '#7a1f1f', '#14532d', '#3b0764', '#1e3a8a', '#7c2d12'];
        $kinds = [QrCode::KIND_LOGIN, QrCode::KIND_REGISTER, QrCode::KIND_REPAIR, QrCode::KIND_CUSTOM];
        $made = 0;

        foreach (range(1, 20) as $i) {
            $kind = $kinds[$i % 4];
            QrCode::create([
                'title' => "TEST QR {$i} - ".self::PLACES[$i % count(self::PLACES)],
                'kind' => $kind,
                'custom_url' => $kind === QrCode::KIND_CUSTOM ? 'https://example.com/test/'.$i : null,
                'color' => $colors[$i % count($colors)],
                'created_by' => $this->sysadmin?->id ?? $this->mHead->id,
            ]);
            $made++;
        }

        return $made;
    }

    // =================================================================================================
    // FAQ, VOTES, MISSED SEARCHES
    // =================================================================================================

    private function faq(): ?int
    {
        if (Faq::query()->where('question', 'like', 'TEST%')->exists()) {
            return null;
        }

        $topics = ['Getting started', 'Filing a ticket', 'Approvals', 'Tracking', 'Account', 'Forms', 'Maintenance', 'Reports'];
        $audiences = [['all'], ['all'], [Role::REQUESTER], [Role::TEACHER], [Role::MAINTENANCE_ADMIN, Role::STAFF], [Role::FINANCE]];
        $made = 0;

        foreach (range(1, 110) as $i) {
            $audience = $audiences[$i % count($audiences)];
            $faq = Faq::create([
                'topic' => $topics[$i % count($topics)],
                'audience' => $audience,
                'is_public' => $audience === ['all'] && $i % 2 === 0,
                'question' => "TEST question {$i}: how do I handle case number {$i}?",
                'answer' => "TEST DATA answer {$i}.\n\n1. Open the page.\n2. Fill in the form.\n3. Wait for the e-mail.\n\n- **Tip:** keep your ticket number.",
                'source' => Faq::SOURCE_CUSTOM,
                'sort_order' => 1000 + $i,
                'is_published' => $i % 9 !== 0,
                'created_by' => $this->mHead->id,
                'updated_by' => $this->mHead->id,
            ]);

            // "Was this helpful?" votes from different students
            foreach (array_slice($this->students, $i % 30, mt_rand(0, 4)) as $voter) {
                FaqFeedback::query()->updateOrCreate(['faq_id' => $faq->id, 'user_id' => $voter->id], ['helpful' => mt_rand(0, 3) > 0]);
            }
            $made++;
        }

        foreach (['wifi password', 'lost id', 'refund', 'leaking roof', 'gym booking', 'printer', 'enrollment', 'scholarship', 'clearance', 'air con', 'comfort room', 'key', 'parking', 'schedule', 'certificate'] as $term) {
            FaqSearchMiss::query()->updateOrCreate(['term' => 'test '.$term], ['count' => mt_rand(1, 40), 'last_seen_at' => now()->subDays(mt_rand(0, 30))]);
        }

        return $made + 15;
    }

    // =================================================================================================
    // NOTIFICATIONS, E-MAIL LOG, AUDIT LOG  (100+ each)
    // =================================================================================================

    private function notifications(): ?int
    {
        if (DB::table('notifications')->where('data', 'like', '%TEST DATA%')->exists()) {
            return null;
        }

        $tickets = MaintenanceRequest::query()->where('reason', 'TEST DATA')->limit(60)->get();
        $targets = array_merge([$this->mHead, $this->vp, $this->finance, $this->president, $this->custodian], array_slice($this->teachers, 0, 5), array_slice($this->staff, 0, 4));
        $events = [['signed', 'approved'], ['approved', 'gave FINAL approval to'], ['rejected', 'disapproved'], ['submitted', 'filed'], ['accepted', 'accepted']];
        $rows = [];

        foreach (range(1, 160) as $i) {
            $t = $tickets->isEmpty() ? null : $tickets[$i % $tickets->count()];
            $to = $targets[$i % count($targets)];
            [$event, $verb] = $events[$i % count($events)];
            $at = now()->subMinutes(mt_rand(1, 60 * 24 * 20));

            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\FormActivity',
                'notifiable_type' => User::class,
                'notifiable_id' => $to->id,
                'data' => json_encode([
                    'form_type' => 'ticket', 'form_id' => $t?->id, 'event' => $event, 'reference_no' => $t?->ticket_no,
                    'title' => "Test user {$verb} ".($t?->ticket_no ?? 'a ticket'),
                    'message' => 'TEST DATA - '.self::PROBLEMS[$i % count(self::PROBLEMS)],
                    'url' => $t ? route('tickets.show', $t->id) : route('notifications.index'),
                ]),
                'read_at' => $i % 3 === 0 ? null : $at->copy()->addMinutes(mt_rand(1, 500)),
                'created_at' => $at, 'updated_at' => $at,
            ];
        }
        DB::table('notifications')->insert($rows);

        return count($rows);
    }

    private function emailLog(): ?int
    {
        if (EmailLog::query()->where('subject', 'like', 'TEST%')->exists()) {
            return null;
        }

        $types = ['ticket_submitted', 'ticket_step_approved', 'ticket_assigned', 'ticket_completed', 'ticket_replied', 'password_reset', 'account_approved'];
        $people = array_merge($this->students, $this->teachers);
        $made = 0;

        foreach (range(1, 130) as $i) {
            $to = $people[$i % count($people)];
            $roll = $i % 10;
            $status = $roll < 7 ? 'delivered' : ($roll < 9 ? 'failed' : 'sending');
            $at = now()->subMinutes(mt_rand(1, 60 * 24 * 30));
            $log = EmailLog::create([
                'status' => $status, 'type' => $types[$i % count($types)], 'to_email' => $to->email, 'to_name' => $to->name, 'user_id' => $to->id,
                'subject' => 'TEST e-mail '.$i.' - '.$types[$i % count($types)],
                'ticket_no' => sprintf('MT-%d-%05d', now()->year, mt_rand(1, 150)),
                'mailer' => 'smtp', 'message_id' => $status === 'delivered' ? Str::uuid().'@test.local' : null,
                'error' => $status === 'failed' ? 'Connection timed out (test data).' : null,
                'body_html' => $types[$i % count($types)] === 'password_reset' ? null : '<p>TEST DATA message body.</p>',
                'attempts' => $status === 'failed' ? mt_rand(1, 3) : 1,
                'delivered_at' => $status === 'delivered' ? $at->copy()->addSeconds(mt_rand(1, 20)) : null,
            ]);
            $log->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            $made++;
        }

        return $made;
    }

    private function auditLog(): ?int
    {
        if (AuditLog::query()->where('description', 'like', 'TEST DATA%')->exists()) {
            return null;
        }

        $actions = [
            ['login', 'Signed in'], ['logout', 'Signed out'], ['login_failed', 'Wrong password'], ['ticket_status', 'Ticket status changed'],
            ['ticket_assigned', 'Ticket assigned'], ['user_updated', 'Account details changed'], ['settings_updated', 'Settings updated'],
            ['category_changed', 'Category edited'], ['faq_updated', 'FAQ entry edited'], ['export', 'Ticket list exported'], ['attachment_downloaded', 'File opened'],
        ];
        $users = array_merge([$this->mHead, $this->vp, $this->finance, $this->president, $this->custodian], $this->teachers, array_slice($this->students, 0, 40));
        $tickets = MaintenanceRequest::query()->where('reason', 'TEST DATA')->pluck('id')->all();
        $rows = [];

        foreach (range(1, 220) as $i) {
            [$action, $text] = $actions[$i % count($actions)];
            $user = $action === 'login_failed' && $i % 2 ? null : $users[$i % count($users)];
            $ticket = in_array($action, ['ticket_status', 'ticket_assigned', 'attachment_downloaded'], true) && $tickets !== [] ? $tickets[$i % count($tickets)] : null;

            $rows[] = [
                'user_id' => $user?->id,
                'action' => $action,
                'description' => 'TEST DATA - '.$text.($user ? ' ('.$user->email.')' : ''),
                'subject_type' => $ticket ? MaintenanceRequest::class : null,
                'subject_id' => $ticket,
                'meta' => null,
                'ip_address' => '192.168.'.mt_rand(0, 20).'.'.mt_rand(2, 250),
                'user_agent' => ['Mozilla/5.0 (Windows NT 10.0) Chrome/120', 'Mozilla/5.0 (Android 13) Chrome/119 Mobile', 'Mozilla/5.0 (iPhone) Safari/17'][$i % 3],
                'created_at' => now()->subMinutes(mt_rand(1, 60 * 24 * 45)),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_logs')->insert($chunk);   // straight insert: the model is append-only by design and seeders skip the request context
        }

        return count($rows);
    }

    // =================================================================================================

    private function userByRole(string $slug): ?User
    {
        return User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', $slug))->orderBy('id')->first();
    }
}
