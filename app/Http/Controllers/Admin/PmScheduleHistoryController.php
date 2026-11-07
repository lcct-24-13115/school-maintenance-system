<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PmSchedule;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Who made, edited, paused / resumed or completed a PM schedule, and when (read from the audit trail). Capability: view_pm_schedules or manage_pm_schedules (read-only). */
class PmScheduleHistoryController extends Controller
{
    public function __invoke(Request $request, PmSchedule $pmSchedule): View
    {
        abort_unless($request->user()?->canViewPm(), 403, 'You do not have access to this page.');

        $pmSchedule->load('creator:id,name', 'facility:id,name', 'equipment:id,name');

        return view('admin.pm-schedules.history', [
            'schedule' => $pmSchedule,
            'entries' => AuditLog::query()->with('user:id,name')
                ->where('subject_type', $pmSchedule->getMorphClass())
                ->where('subject_id', $pmSchedule->getKey())
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }
}