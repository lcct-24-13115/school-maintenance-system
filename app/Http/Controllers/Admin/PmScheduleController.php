<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Preventive Maintenance schedules (PM Scheduler, Phase 1): list, make, edit, activate / deactivate, delete.
 * Capability: manage_pm_schedules (others get 403). Tickets are NOT made here yet (Phase 2).
 */
class PmScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeView($request);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'facility_id' => $request->query('facility_id'),
            'equipment_id' => $request->query('equipment_id'),
            'frequency_type' => $request->query('frequency_type'),
            'active' => $request->query('active'),
        ];

        $schedules = PmSchedule::query()
            ->with(['facility:id,name', 'equipment:id,name', 'assignee:id,name'])
            ->when($filters['q'] !== '', fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%'))
            ->when(filled($filters['facility_id']), fn ($q) => $q->where('facility_id', (int) $filters['facility_id']))
            ->when(filled($filters['equipment_id']), fn ($q) => $q->where('equipment_id', (int) $filters['equipment_id']))
            ->when(array_key_exists((string) $filters['frequency_type'], PmSchedule::frequencyLabels()), fn ($q) => $q->where('frequency_type', $filters['frequency_type']))
            ->when(in_array($filters['active'], ['0', '1'], true), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderByDesc('is_active')
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.pm-schedules.index', [
            'schedules' => $schedules,
            'filters' => $filters,
            'facilities' => Facility::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'equipmentList' => Equipment::orderBy('name')->get(['id', 'name']),
            'runs' => \App\Models\PmJobRun::latest('id')->limit(8)->get(),
            'frequencies' => PmSchedule::frequencyLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeManage($request);

        $schedule = new PmSchedule([
            'frequency_type' => PmSchedule::MONTHLY,
            'frequency_interval' => 1,
            'lead_time_days' => 7,
            'ticket_priority' => PmSchedule::PRIORITY_FCFS,
            'is_active' => true,
            'start_date' => now(config('app.timezone'))->toDateString(),
        ]);

        return view('admin.pm-schedules.form', $this->formData($schedule));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);

        if ($errors = $this->problems($data)) {
            return back()->withInput()->withErrors($errors);
        }

        $items = $this->checklistLines($data);
        unset($data['checklist']);

        $schedule = PmSchedule::create($data + ['created_by' => $request->user()->id]);
        $this->syncChecklist($schedule, $items);

        AuditLogger::log(
            AuditLogger::PM_SCHEDULE_CHANGED,
            "PM schedule created: {$schedule->title}",
            $schedule,
            $request->user(),
            ['frequency' => $schedule->frequencyText(), 'next_due_date' => $schedule->next_due_date?->toDateString()],
        );

        return redirect()->route('admin.pm-schedules.index')->with('status', "PM schedule \"{$schedule->title}\" created.");
    }

    public function edit(Request $request, PmSchedule $pmSchedule): View
    {
        $this->authorizeManage($request);

        return view('admin.pm-schedules.form', $this->formData($pmSchedule));
    }

    public function update(Request $request, PmSchedule $pmSchedule): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);

        if ($errors = $this->problems($data)) {
            return back()->withInput()->withErrors($errors);
        }

        // read the checklist first: it is not a column of the schedule
        $items = $this->checklistLines($data);
        unset($data['checklist']);

        $pmSchedule->fill($data);
        $changed = array_keys($pmSchedule->getDirty());
        $pmSchedule->save();

        $this->syncChecklist($pmSchedule, $items);

        if ($changed !== []) {
            AuditLogger::log(
                AuditLogger::PM_SCHEDULE_CHANGED,
                "PM schedule updated: {$pmSchedule->title}. Changed: ".implode(', ', $changed),
                $pmSchedule,
                $request->user(),
                ['next_due_date' => $pmSchedule->next_due_date?->toDateString()],
            );
        }

        return redirect()->route('admin.pm-schedules.index')->with('status', "PM schedule \"{$pmSchedule->title}\" saved.");
    }

    /** Pause / resume. Resuming moves the next due date to the next FUTURE date, so a paused schedule never floods tickets. */
    public function toggle(Request $request, PmSchedule $pmSchedule): RedirectResponse
    {
        $this->authorizeManage($request);

        $pmSchedule->is_active = ! $pmSchedule->is_active;

        if ($pmSchedule->is_active) {
            $pmSchedule->next_due_date = $pmSchedule->firstDueOnOrAfter(now(config('app.timezone'))->toDateString());
        }

        $pmSchedule->save();

        $word = $pmSchedule->is_active ? 'activated' : 'deactivated';

        AuditLogger::log(AuditLogger::PM_SCHEDULE_CHANGED, "PM schedule {$word}: {$pmSchedule->title}", $pmSchedule, $request->user());

        return back()->with('status', "PM schedule \"{$pmSchedule->title}\" {$word}.");
    }

    public function destroy(Request $request, PmSchedule $pmSchedule): RedirectResponse
    {
        $this->authorizeManage($request);

        $title = $pmSchedule->title;
        $pmSchedule->delete();

        AuditLogger::log(AuditLogger::PM_SCHEDULE_CHANGED, "PM schedule deleted: {$title}", null, $request->user());

        return redirect()->route('admin.pm-schedules.index')->with('status', "PM schedule \"{$title}\" deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $maintenanceRoleIds = Role::whereIn('slug', Role::maintenanceSlugs())->pluck('id');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'ticket_priority' => ['required', Rule::in(array_keys(PmSchedule::priorityLabels()))],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where(
                fn ($q) => $q->where('is_active', true)->whereIn('role_id', $maintenanceRoleIds)
            )],
            'frequency_type' => ['required', Rule::in(array_keys(PmSchedule::frequencyLabels()))],
            'frequency_interval' => ['required', 'integer', 'min:1', 'max:365'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'schedule_basis' => ['nullable', Rule::in(array_keys(PmSchedule::basisLabels()))],
            'checklist' => ['nullable', 'string', 'max:6000'],
        ], [
            'title.required' => 'Type a title, for example "Aircon Cleaning".',
            'facility_id.required' => 'Choose the facility.',
            'frequency_interval.min' => 'The interval must be at least 1.',
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'assigned_to.exists' => 'Choose an active maintenance staff member.',
        ]);

        $data['title'] = trim($data['title']);

        if (blank($data['schedule_basis'] ?? null)) {
            unset($data['schedule_basis']);   // not sent: keep what is saved (new schedules get "fixed" from the database)
        }
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /** @return array<string, string> field => message (empty = fine) */
    private function problems(array $data): array
    {
        $errors = [];

        if (! empty($data['equipment_id']) && ! Equipment::whereKey($data['equipment_id'])->where('facility_id', $data['facility_id'])->exists()) {
            $errors['equipment_id'] = 'That equipment does not belong to the chosen facility.';
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function formData(PmSchedule $schedule): array
    {
        return [
            'schedule' => $schedule,
            'facilities' => Facility::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'equipmentOptions' => Equipment::orderBy('name')->get(['id', 'name', 'facility_id'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'facility_id' => $e->facility_id])->values(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'assignees' => User::where('is_active', true)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
                ->orderBy('name')->get(['id', 'name']),
            'frequencies' => PmSchedule::frequencyLabels(),
            'priorities' => PmSchedule::priorityLabels(),
        ];
    }

    /** @return list<string> one task per line; blank lines, "- " / bullet marks and repeats are dropped; at most 30 */
    private function checklistLines(array $data): array
    {
        $items = [];

        foreach (preg_split('/\R/', (string) ($data['checklist'] ?? '')) ?: [] as $line) {
            $line = trim((string) preg_replace('/^[\s\-\*\x{2022}]+/u', '', $line));

            if ($line !== '') {
                $items[] = mb_substr($line, 0, 200);
            }
        }

        return array_slice(array_values(array_unique($items)), 0, 30);
    }

    /** Replace the template. Tickets that were already made keep their own copy. */
    private function syncChecklist(PmSchedule $schedule, array $items): void
    {
        $schedule->checklistItems()->delete();

        foreach ($items as $i => $label) {
            $schedule->checklistItems()->create(['label' => $label, 'sort_order' => $i]);
        }
    }

    /** Read-only pages (list): view_pm_schedules or manage_pm_schedules. Everything that changes data uses authorizeManage(). */
    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->canViewPm(), 403, 'You do not have access to this page.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_pm_schedules'), 403, 'You do not have access to this page.');
    }
}