<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Facility;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Equipment list for the PM Scheduler (Phase 1). Capability: manage_pm_schedules (others get 403). */
class EquipmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        return view('admin.equipment.index', [
            'equipment' => Equipment::query()->with('facility:id,name')->withCount('pmSchedules')
                ->orderBy('name')->paginate(20)->withQueryString(),
            'facilities' => Facility::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $item = Equipment::create($this->validated($request));

        AuditLogger::log(AuditLogger::EQUIPMENT_CHANGED, "Equipment added: {$item->name}", $item, $request->user());

        return back()->with('status', "Equipment \"{$item->name}\" added.");
    }

    public function update(Request $request, Equipment $equipment): RedirectResponse
    {
        $this->authorizeManage($request);

        $equipment->fill($this->validated($request));
        $changed = array_keys($equipment->getDirty());
        $equipment->save();

        if ($changed !== []) {
            AuditLogger::log(AuditLogger::EQUIPMENT_CHANGED, "Equipment updated: {$equipment->name}. Changed: ".implode(', ', $changed), $equipment, $request->user());
        }

        return back()->with('status', "Equipment \"{$equipment->name}\" saved.");
    }

    public function destroy(Request $request, Equipment $equipment): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($equipment->pmSchedules()->exists()) {
            return back()->with('error', "\"{$equipment->name}\" still has PM schedules. Delete or change those first.");
        }

        $name = $equipment->name;
        $equipment->delete();

        AuditLogger::log(AuditLogger::EQUIPMENT_CHANGED, "Equipment deleted: {$name}", null, $request->user());

        return back()->with('status', "Equipment \"{$name}\" deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Type the equipment name.',
            'facility_id.required' => 'Choose the facility.',
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_pm_schedules'), 403, 'You do not have access to this page.');
    }
}