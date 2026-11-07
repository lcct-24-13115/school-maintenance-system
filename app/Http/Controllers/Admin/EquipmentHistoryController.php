<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Maintenance history of ONE piece of equipment: every preventive-maintenance ticket made from its PM schedules,
 * with the due date, the day the work was done, the technician and the checklist result.
 * Capability: view_pm_schedules or manage_pm_schedules (others get 403). Tickets are still filtered with visibleTo(), like every ticket list.
 */
class EquipmentHistoryController extends Controller
{
    public function __invoke(Request $request, Equipment $equipment): View
    {
        abort_unless($request->user()?->canViewPm(), 403, 'You do not have access to this page.');

        $tz = config('app.timezone');
        $today = now($tz)->toDateString();
        $open = TicketStatus::pendingValues();

        $status = (string) $request->query('status', '');
        $status = in_array($status, ['open', 'done', 'overdue'], true) ? $status : '';
        $scheduleId = (int) $request->query('schedule', 0);

        // All the tickets of this equipment that the user may see (the numbers on top use this, without the filters).
        $base = MaintenanceRequest::query()
            ->visibleTo($request->user())
            ->whereIn('pm_schedule_id', PmSchedule::query()->where('equipment_id', $equipment->id)->select('id'));

        $stats = [
            'total' => (clone $base)->count(),
            'done' => (clone $base)->whereNotNull('pm_completed_date')->count(),
            'open' => (clone $base)->whereIn('status', $open)->count(),
            'overdue' => (clone $base)->whereIn('status', $open)->whereDate('pm_due_date', '<', $today)->count(),
            'last_done' => ($last = (clone $base)->max('pm_completed_date')) ? Carbon::parse($last, $tz) : null,
        ];

        // The list, with the filters.
        $list = clone $base;

        if ($status === 'open') {
            $list->whereIn('status', $open);
        } elseif ($status === 'done') {
            $list->whereNotNull('pm_completed_date');
        } elseif ($status === 'overdue') {
            $list->whereIn('status', $open)->whereDate('pm_due_date', '<', $today);
        }

        if ($scheduleId > 0) {
            $list->where('pm_schedule_id', $scheduleId);
        }

        $tickets = $list
            ->with(['pmSchedule:id,title', 'pmTechnician:id,name', 'assignee:id,name'])
            ->withCount([
                'pmChecklist as checklist_total',
                'pmChecklist as checklist_done' => fn ($q) => $q->where('is_done', true),
            ])
            ->orderByDesc('pm_due_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $schedules = $equipment->pmSchedules()->with('assignee:id,name')->orderBy('title')->get();

        return view('admin.equipment.history', [
            'equipment' => $equipment->load('facility:id,name'),
            'schedules' => $schedules,
            'tickets' => $tickets,
            'stats' => $stats,
            'filters' => ['status' => $status, 'schedule' => $scheduleId > 0 ? (string) $scheduleId : ''],
            'today' => $today,
        ]);
    }
}