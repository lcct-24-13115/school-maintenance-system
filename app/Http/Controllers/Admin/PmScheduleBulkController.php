<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PmSchedule;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PM schedules: pause / resume many at once, and duplicate one. Capability manage_pm_schedules (others get 403).
 * Every schedule that really changes gets its own line in the audit trail, like the single Activate / Deactivate button.
 */
class PmScheduleBulkController extends Controller
{
    public function bulk(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:pm_schedules,id'],
        ], [
            'ids.required' => 'Tick at least one schedule first.',
            'ids.min' => 'Tick at least one schedule first.',
        ]);

        $resume = $data['action'] === 'resume';
        $today = now(config('app.timezone'))->toDateString();
        $changed = 0;

        foreach (PmSchedule::query()->whereIn('id', $data['ids'])->orderBy('id')->get() as $schedule) {
            if ($schedule->is_active === $resume) {
                continue;   // already in that state
            }

            $schedule->is_active = $resume;

            if ($resume) {
                // same rule as the single button: a resumed schedule jumps to the next FUTURE date
                $schedule->next_due_date = $schedule->firstDueOnOrAfter($today);
            }

            $schedule->save();
            $changed++;

            AuditLogger::log(
                AuditLogger::PM_SCHEDULE_CHANGED,
                'PM schedule '.($resume ? 'activated' : 'deactivated').": {$schedule->title}",
                $schedule,
                $request->user(),
                ['bulk' => true],
            );
        }

        $word = $resume ? 'resumed' : 'paused';
        $skipped = count($data['ids']) - $changed;

        return back()->with('status', "{$changed} ".Str::plural('schedule', $changed)." {$word}."
            .($skipped > 0 ? " {$skipped} already ".($resume ? 'active' : 'paused').'.' : ''));
    }

    /** A copy with the same settings and checklist. It starts INACTIVE, so it cannot make tickets before it is reviewed. */
    public function duplicate(Request $request, PmSchedule $pmSchedule): RedirectResponse
    {
        $this->authorizeManage($request);

        $copy = $pmSchedule->replicate(['next_due_date', 'anchor_date', 'last_completed_date', 'last_generated_at']);
        $copy->title = Str::limit('Copy of '.$pmSchedule->title, 150, '');
        $copy->is_active = false;
        $copy->created_by = $request->user()->id;
        $copy->save();

        foreach ($pmSchedule->checklistItems as $i => $item) {
            $copy->checklistItems()->create(['label' => $item->label, 'sort_order' => $i]);
        }

        AuditLogger::log(
            AuditLogger::PM_SCHEDULE_CHANGED,
            "PM schedule duplicated: {$pmSchedule->title} -> {$copy->title}",
            $copy,
            $request->user(),
            ['copied_from' => $pmSchedule->id],
        );

        return redirect()->route('admin.pm-schedules.edit', $copy)
            ->with('status', 'Copy made and set to inactive. Check the title and dates, then activate it.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_pm_schedules'), 403, 'You do not have access to this page.');
    }
}