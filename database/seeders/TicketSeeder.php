<?php

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestItem;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * ~40 SAMPLE tickets spread over the last 12 months, in every status, with a full history each.
 * Needed to check the queue, filters, analytics (4 views) and reports.
 * Same data every run (fixed random seed). Delete before going live.
 */
class TicketSeeder extends Seeder
{
    private const TOTAL = 40;

    private const PATH = [
        TicketStatus::Submitted, TicketStatus::PendingAuthorization, TicketStatus::Approved,
        TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::WorkCompleted, TicketStatus::Closed,
    ];

    private const LOCATIONS = [
        'Grade 5 Room 204', 'Grade 2 Room 105', 'Comfort Room - Ground Floor', 'Computer Laboratory',
        'Science Laboratory', 'Faculty Room', 'Library', 'Guidance Office', 'Canteen',
        'Main Gate', 'Gymnasium', 'Registrar Office', 'Chapel', 'Cashier Window',
    ];

    private const EMERGENCY_REASONS = [
        'Water leak is flooding the corridor.',
        'Exposed live wire near students.',
        'No power in the computer lab during exams.',
        'Broken glass window beside the playground.',
        'Clogged comfort room is unusable for the whole floor.',
        'Aircon fire smell reported in the faculty room.',
    ];

    private const PROBLEMS = [
        'Spare part was not in stock; waited for delivery.',
        'Work was delayed because the room was in use for classes.',
        'Old wiring needed to be replaced first.',
    ];

    private const RECOMMENDATIONS = [
        'Schedule preventive maintenance every semester.',
        'Keep common spare parts in stock.',
        'Replace the whole unit instead of repeated repairs.',
    ];

    public function run(): void
    {
        if (MaintenanceRequest::query()->exists()) {
            return;   // do not duplicate on a second `db:seed`
        }

        $requesters = $this->usersWith(Role::REQUESTER);
        $staff = $this->usersWith(Role::MAINTENANCE_ADMIN);
        $president = $this->usersWith(Role::PRESIDENT)->first();
        $categories = Category::where('is_active', true)->get();

        if ($requesters->isEmpty() || $staff->isEmpty() || ! $president || $categories->isEmpty()) {
            $this->command?->warn('TicketSeeder skipped: run RoleSeeder, DepartmentSeeder, CategorySeeder and UserSeeder first.');

            return;
        }

        mt_srand(2026);   // fixed sample data

        $cap = now()->subMinutes(5);
        $start = now()->subMonths(12)->startOfDay()->addDays(3);

        // the 8 most recent tickets sit at every stage so the board and queue have something to show
        $recentStatuses = [
            TicketStatus::WorkCompleted, TicketStatus::InProgress, TicketStatus::Assigned, TicketStatus::Approved,
            TicketStatus::PendingAuthorization, TicketStatus::PendingAuthorization, TicketStatus::Submitted, TicketStatus::Submitted,
        ];
        $recentDaysAgo = [9, 7, 6, 4, 3, 2, 1, 0];

        $emergencyAt = [3, 12, 19, 28, 35, 38];
        $confidentialAt = [6, 17, 30];

        for ($i = 0; $i < self::TOTAL; $i++) {
            $recent = $i >= self::TOTAL - 8;
            $k = $i - (self::TOTAL - 8);

            $final = $recent ? $recentStatuses[$k] : $this->olderStatus($i);

            $submittedAt = $recent
                ? now()->subDays($recentDaysAgo[$k])->setTime(7 + $k, mt_rand(0, 59))
                : $start->copy()->addDays((int) round($i * 335 / 31) + mt_rand(0, 3))->setTime(mt_rand(7, 16), mt_rand(0, 59));
            if ($submittedAt->gt($cap)) {
                $submittedAt = $cap->copy()->subMinutes(25);
            }

            $category = $categories[mt_rand(0, $categories->count() - 1)];   // mt_rand keeps the sample data identical every run
            $requester = $requesters[$i % $requesters->count()];
            $emergency = in_array($i, $emergencyAt, true);

            $ticket = $this->createTicket($i, $final, $submittedAt, $category, $requester, $staff, $president, $emergency,
                in_array($i, $confidentialAt, true), $cap, ! $recent || $k === 0);

            $this->addItems($ticket, $category);
        }
    }

    private function olderStatus(int $i): TicketStatus
    {
        return match (true) {
            $i % 10 === 5 => TicketStatus::Disapproved,
            $i % 13 === 8 => TicketStatus::WorkCompleted,
            default => TicketStatus::Closed,
        };
    }

    private function createTicket(
        int $i, TicketStatus $final, Carbon $submittedAt, Category $category, User $requester,
        $staff, User $president, bool $emergency, bool $confidential, Carbon $cap, bool $mayHaveNotes,
    ): MaintenanceRequest {
        $long = $submittedAt->lt(now()->subDays(30));
        $assignee = $staff[$i % $staff->count()];

        // ---- walk the path, remembering when each status was reached -------------------------
        $path = $final === TicketStatus::Disapproved
            ? [TicketStatus::Submitted, TicketStatus::PendingAuthorization, TicketStatus::Disapproved]
            : array_slice(self::PATH, 0, array_search($final, self::PATH, true) + 1);

        $times = [];
        $t = $submittedAt->copy();
        foreach ($path as $n => $status) {
            if ($n > 0) {
                $t = $this->advance($t, $status, $long, $cap);
            }
            $times[$status->value] = $t->copy();
        }
        $last = end($times);

        // ---- the ticket itself ---------------------------------------------------------------
        $reached = fn (TicketStatus $s) => isset($times[$s->value]);
        $type = mt_rand(1, 100) <= 60 ? $category->type : null;
        $descriptions = $this->descriptionsFor($category->name);

        $ticket = new MaintenanceRequest;
        $ticket->forceFill([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id,
            'category_id' => $category->id,
            'request_type' => $type,
            'maintenance_type' => ['repair', 'replacement', 'installation', 'improvement'][mt_rand(0, 3)],
            'location' => self::LOCATIONS[mt_rand(0, count(self::LOCATIONS) - 1)],
            'description' => $descriptions[mt_rand(0, count($descriptions) - 1)],
            'needed_by' => $submittedAt->copy()->addDays(7)->toDateString(),
            'reason' => 'Affects classes and daily operations.',
            'is_emergency' => $emergency,
            'emergency_reason' => $emergency ? self::EMERGENCY_REASONS[$i % count(self::EMERGENCY_REASONS)] : null,
            'emergency_flagged_by' => $emergency ? $requester->id : null,
            'emergency_flagged_at' => $emergency ? $submittedAt->copy()->addMinutes(2) : null,
            'is_confidential' => $confidential,
            'status' => $final,
            'assigned_to' => $reached(TicketStatus::Assigned) ? $assignee->id : null,
            'assigned_at' => $reached(TicketStatus::Assigned) ? $times['assigned'] : null,
            'president_id' => $reached(TicketStatus::Approved) ? $president->id : null,
            'president_approved_at' => $reached(TicketStatus::Approved) ? $times['approved'] : null,
            'president_remarks' => $reached(TicketStatus::Approved) ? 'Authorized.' : null,
            'problems_encountered' => ($reached(TicketStatus::WorkCompleted) && $mayHaveNotes && mt_rand(1, 3) === 1)
                ? self::PROBLEMS[mt_rand(0, count(self::PROBLEMS) - 1)] : null,
            'recommendations' => ($reached(TicketStatus::WorkCompleted) && $mayHaveNotes && mt_rand(1, 3) === 1)
                ? self::RECOMMENDATIONS[mt_rand(0, count(self::RECOMMENDATIONS) - 1)] : null,
            'submitted_at' => $submittedAt,
            'completed_at' => $reached(TicketStatus::WorkCompleted) ? $times['work_completed'] : null,
            'closed_at' => $reached(TicketStatus::Closed) ? $times['closed'] : null,
            'created_at' => $submittedAt,
            'updated_at' => $last,
        ])->save();

        // ---- history -------------------------------------------------------------------------
        $this->log($ticket, RequestStatusLog::ACTION_STATUS, $requester->id, $submittedAt, null, TicketStatus::Submitted, 'Ticket submitted.');

        if ($emergency) {
            $this->log($ticket, RequestStatusLog::ACTION_EMERGENCY, $requester->id, $submittedAt->copy()->addMinutes(2),
                null, null, $ticket->emergency_reason, ['flagged' => true]);
        }

        $staffAdmin = $staff->first();
        $previous = TicketStatus::Submitted;

        foreach ($path as $n => $status) {
            if ($n === 0) {
                continue;
            }
            $at = $times[$status->value];

            [$action, $by, $note, $meta] = match ($status) {
                TicketStatus::PendingAuthorization => [RequestStatusLog::ACTION_STATUS, $staffAdmin->id, 'Sent for authorization.', null],
                TicketStatus::Approved => [RequestStatusLog::ACTION_AUTHORIZATION, $president->id, 'Authorized.', null],
                TicketStatus::Disapproved => [RequestStatusLog::ACTION_AUTHORIZATION, $president->id, 'Not needed at this time.', null],
                TicketStatus::Assigned => [RequestStatusLog::ACTION_ASSIGNMENT, $staffAdmin->id, "Assigned to {$assignee->name}.", ['from_user_id' => null, 'to_user_id' => $assignee->id]],
                TicketStatus::InProgress => [RequestStatusLog::ACTION_STATUS, $assignee->id, 'Work started.', null],
                TicketStatus::WorkCompleted => [RequestStatusLog::ACTION_STATUS, $assignee->id, 'Work completed.', null],
                TicketStatus::Closed => [RequestStatusLog::ACTION_STATUS, $staffAdmin->id, 'Ticket closed.', null],
            };

            $this->log($ticket, $action, $by, $at, $previous, $status, $note, $meta);
            $previous = $status;
        }

        return $ticket;
    }

    /** How long after the previous step this status is reached. Older tickets drag more. */
    private function advance(Carbon $from, TicketStatus $status, bool $long, Carbon $cap): Carbon
    {
        $next = match ($status) {
            TicketStatus::PendingAuthorization => $from->copy()->addMinutes(mt_rand(20, 240)),
            TicketStatus::Approved, TicketStatus::Disapproved => $from->copy()->addHours(mt_rand(4, 48)),
            TicketStatus::Assigned => $from->copy()->addHours(mt_rand(1, 24)),
            TicketStatus::InProgress => $from->copy()->addHours(mt_rand(1, 12)),
            TicketStatus::WorkCompleted => $from->copy()->addHours($long ? mt_rand(24, 14 * 24) : mt_rand(6, 48)),
            TicketStatus::Closed => $from->copy()->addHours(mt_rand(12, 72)),
            default => $from->copy(),
        };

        return $next->gt($cap) ? $cap->copy() : $next;
    }

    private function log(MaintenanceRequest $ticket, string $action, ?int $userId, Carbon $at, ?TicketStatus $from, ?TicketStatus $to, ?string $note, ?array $meta = null): void
    {
        RequestStatusLog::forceCreate([
            'request_id' => $ticket->id,
            'user_id' => $userId,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'meta' => $meta,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function addItems(MaintenanceRequest $ticket, Category $category): void
    {
        if (! $ticket->request_type) {
            return;
        }

        $pool = match ($ticket->request_type) {
            Category::TYPE_SUPPLIES => ['Bond paper (ream)', 'Whiteboard marker', 'Trash bags (roll)', 'Disinfectant (gallon)'],
            Category::TYPE_EQUIPMENT => ['Replacement compressor', 'Electric fan', 'Water pump motor', 'Student armchair'],
            default => ['PVC pipe 1/2"', 'Faucet', 'Electrical wire (roll)', 'LED bulb'],
        };

        for ($n = mt_rand(1, 2); $n > 0; $n--) {
            RequestItem::create([
                'request_id' => $ticket->id,
                'item_name' => $pool[mt_rand(0, count($pool) - 1)],
                'quantity' => mt_rand(1, 5),
                'unit' => 'pc',
            ]);
        }
    }

    private function descriptionsFor(string $categoryName): array
    {
        $name = strtolower($categoryName);

        return match (true) {
            str_contains($name, 'plumbing') => ['Leaking faucet in the comfort room.', 'Clogged drain in the hallway sink.', 'Broken flush handle.'],
            str_contains($name, 'office') => ['Bond paper and markers needed for the office.', 'Folders and staplers needed for enrollment.'],
            str_contains($name, 'cleaning') => ['Out of disinfectant and mops.', 'Trash bags and brooms needed.'],
            str_contains($name, 'carpentry') => ['Broken door hinge.', 'Cracked wall near the stairs.', 'Window latch needs replacement.'],
            str_contains($name, 'computer') => ['Computer does not boot.', 'Projector shows no display.'],
            str_contains($name, 'air') => ['Aircon is not cooling.', 'Aircon is leaking water.'],
            str_contains($name, 'pump') => ['Water pump makes a loud noise.', 'No water pressure on the second floor.'],
            str_contains($name, 'furniture') => ['Broken student armchairs.', 'Teacher table drawer is stuck.'],
            str_contains($name, 'electrical') => ['Flickering fluorescent light.', 'Outlet sparks when plugged in.', 'Ceiling lights need replacing.'],
            default => ['General repair needed.', 'Needs inspection and repair.'],
        };
    }

    private function usersWith(string $slug)
    {
        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $slug))
            ->orderBy('id')->get();
    }
}
