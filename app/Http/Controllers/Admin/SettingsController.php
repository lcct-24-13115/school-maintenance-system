<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Editable system settings (Merge Phase M1): SLA hours and the reopen window. Capability: manage_settings. */
class SettingsController extends Controller
{
    /** setting key => label shown on the page */
    public const KEYS = ['sla_hours_routine', 'sla_hours_emergency', 'reopen_window_days', 'autoclose_waiting_days'];

    public function edit(Request $request): View
    {
        $this->authorizeManage($request);

        return view('admin.settings', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => Setting::int($k)])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'sla_hours_routine' => ['required', 'integer', 'min:1', 'max:8760'],
            'sla_hours_emergency' => ['required', 'integer', 'min:1', 'max:8760'],
            'reopen_window_days' => ['required', 'integer', 'min:0', 'max:365'],
            'autoclose_waiting_days' => ['nullable', 'integer', 'min:0', 'max:365'],   // Phase 9; left alone when the field is missing
            'recalculate_open' => ['nullable', 'boolean'],
        ]);

        // Remember what actually changed (old -> new) before saving, for the audit trail.
        $changes = [];
        foreach (self::KEYS as $key) {
            $old = Setting::int($key);
            $new = (int) ($data[$key] ?? $old);

            if ($old !== $new) {
                $changes[$key] = [$old, $new];
            }
        }

        foreach (self::KEYS as $key) {
            Setting::put($key, $data[$key] ?? Setting::int($key));
        }

        if ($changes !== []) {
            AuditLogger::log(
                AuditLogger::SETTINGS_UPDATED,
                'Settings changed: '.implode(', ', array_keys($changes)),
                null,
                $request->user(),
                ['changes' => $changes],
            );
        }

        // Optional: re-date the tickets that are still pending. Quietly, so history/guards are untouched.
        if ($request->boolean('recalculate_open')) {
            MaintenanceRequest::query()->pending()->chunkById(200, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $ticket->forceFill([
                        'due_at' => MaintenanceRequest::computeDueAt($ticket->submitted_at, (bool) $ticket->is_emergency),
                    ])->saveQuietly();
                }
            });
        }

        return redirect()->route('admin.settings.edit')->with('status', 'Settings saved.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_settings'), 403, 'You do not have access to this page.');
    }
}
