<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use App\Services\MaintenanceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Maintenance Calendar. Capability view_pm_schedules or manage_pm_schedules (others get 403).
 *
 *   GET /maintenance-calendar         the page (PM E4): a month grid + a list of the same month. No JavaScript, no npm package.
 *   GET /maintenance-calendar/events  the JSON feed (Phase 1), same filters.
 *
 * Nothing is saved: the upcoming occurrences are computed from the recurrence rules on every call (MaintenanceCalendar::events()).
 */
class MaintenanceCalendarController extends Controller
{
    /** The month page. ?month=2026-11&facility_id=&equipment_id=&category_id=&assignee_id=&status= */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user?->canViewPm(), 403, 'You do not have access to this page.');

        $data = $request->validate([
            'month' => ['nullable', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(array_keys(MaintenanceCalendar::statuses()))],
        ]);

        $tz = config('app.timezone');
        $todayKey = now($tz)->toDateString();
        $monthKey = $data['month'] ?? substr($todayKey, 0, 7);
        $month = CarbonImmutable::parse($monthKey.'-01', $tz)->startOfDay();

        $id = fn (string $key) => filled($data[$key] ?? null) ? (int) $data[$key] : null;
        $filters = [
            'facility_id' => $id('facility_id'),
            'equipment_id' => $id('equipment_id'),
            'category_id' => $id('category_id'),
            'assignee_id' => $id('assignee_id'),
            'status' => $data['status'] ?? null,
        ];

        // the grid starts on the Sunday on or before the 1st and ends on the Saturday on or after the last day
        // (dayOfWeek: Sunday = 0 ... Saturday = 6)
        $lastDay = $month->endOfMonth()->startOfDay();
        $gridStart = $month->subDays($month->dayOfWeek);
        $gridEnd = $lastDay->addDays(6 - $lastDay->dayOfWeek);

        $events = MaintenanceCalendar::events($user, $gridStart, $gridEnd, $filters);

        $byDate = [];
        foreach ($events as $event) {
            $byDate[$event['start']][] = $event;
        }

        $days = [];
        for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'day' => (int) $day->format('j'),
                'in_month' => $day->format('Y-m') === $month->format('Y-m'),
                'today' => $key === $todayKey,
                'events' => $byDate[$key] ?? [],
            ];
        }

        // the list under the grid and the counters only cover the month itself, not the grey days around it
        $inMonth = array_values(array_filter($events, fn (array $e) => substr($e['start'], 0, 7) === $month->format('Y-m')));
        $counts = array_fill_keys(array_keys(MaintenanceCalendar::statuses()), 0);
        foreach ($inMonth as $event) {
            $counts[$event['extendedProps']['status']]++;
        }

        return view('maintenance.calendar', [
            'weeks' => array_chunk($days, 7),
            'monthKey' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'prevKey' => $month->subMonth()->format('Y-m'),
            'nextKey' => $month->addMonth()->format('Y-m'),
            'thisKey' => substr($todayKey, 0, 7),
            'filters' => $filters,
            'statuses' => MaintenanceCalendar::statuses(),
            'counts' => $counts,
            'agenda' => $inMonth,
            'canManage' => (bool) $user->can_do('manage_pm_schedules'),
            'facilities' => Facility::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'equipmentList' => Equipment::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'assignees' => User::where('is_active', true)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canViewPm(), 403, 'You do not have access to this page.');

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(array_keys(MaintenanceCalendar::statuses()))],
        ]);

        $tz = config('app.timezone');
        $from = CarbonImmutable::parse($data['start'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['end'], $tz)->startOfDay();

        if ($from->diffInDays($to) > MaintenanceCalendar::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end' => 'The date range is too long (at most '.MaintenanceCalendar::MAX_RANGE_DAYS.' days).',
            ]);
        }

        $id = fn (string $key) => filled($data[$key] ?? null) ? (int) $data[$key] : null;

        return response()->json(MaintenanceCalendar::events($request->user(), $from, $to, [
            'facility_id' => $id('facility_id'),
            'equipment_id' => $id('equipment_id'),
            'category_id' => $id('category_id'),
            'assignee_id' => $id('assignee_id'),
            'status' => $data['status'] ?? null,
        ]));
    }
}
