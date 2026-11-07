<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Services\FormNotifier;
use App\Services\PmCompletion;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** PM integration, Phase B: the "Complete PM" form on the ticket page. */
class PmCompletionController extends Controller
{
    public function store(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        // {ticket} is resolved through visibleTo() (hidden = 404); this checks the "work" capability (403).
        Gate::authorize('work', $ticket);

        $data = $request->validateWithBag('pmcomplete', [
            'completed_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'technician_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['nullable', 'array'],
            'checklist.*.done' => ['nullable', 'boolean'],
            'checklist.*.note' => ['nullable', 'string', 'max:500'],
        ], [
            'completed_date.required' => 'Choose the date the work was completed.',
            'completed_date.before_or_equal' => 'The completed date cannot be in the future.',
            'technician_id.required' => 'Choose the technician who did the work.',
        ]);

        $back = redirect()->to(route('tickets.show', $ticket).'#pm-complete');

        try {
            $result = PmCompletion::complete($ticket, $request->user(), $data);
        } catch (DomainException $e) {
            return $back->with('pm_error', $e->getMessage())->withInput();
        }

        FormNotifier::work($result['ticket'], $request->user(), 'completed');

        $next = $result['schedule']?->next_due_date;
        $message = 'PM completed and recorded.'.($next ? ' The next one is due '.$next->format('M j, Y').'.' : '');

        return $back->with('status', $message);
    }
}