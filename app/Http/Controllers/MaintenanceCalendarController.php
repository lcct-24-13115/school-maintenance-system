<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance Calendar (Phase 1): the JSON feed of the calendar. Capability view_pm_schedules or manage_pm_schedules (others get 403).
 * GET /maintenance-calendar/events?start=2026-11-01&end=2026-12-31&facility_id=&equipment_id=&category_id=&assignee_id=&status=
 * Nothing is saved: the upcoming occurrences are computed from the recurrence rules on every call.
 */
class MaintenanceCalendarController extends Controller
{
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