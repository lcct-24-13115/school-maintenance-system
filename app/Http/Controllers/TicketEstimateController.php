<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Services\TicketEstimate;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** POST tickets/{ticket}/estimate: the Head of Maintenance tells the requester the estimated completion date. */
class TicketEstimateController extends Controller
{
    public function store(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        // {ticket} is already resolved through visibleTo() (hidden = 404); a visible ticket without the role is a 403.
        abort_unless(TicketEstimate::canSet($request->user(), $ticket), 403);

        $data = $request->validateWithBag('estimate', [
            'estimated_date' => ['required', 'date', 'after_or_equal:today'],
            'estimate_message' => ['nullable', 'string', 'max:500'],
        ], [
            'estimated_date.required' => 'Choose the estimated completion date.',
            'estimated_date.date' => 'Choose a valid date.',
            'estimated_date.after_or_equal' => 'The estimated completion date cannot be in the past.',
        ]);

        $back = redirect()->to(route('tickets.show', $ticket).'#estimate');

        try {
            TicketEstimate::set($ticket, $request->user(), $data['estimated_date'], $data['estimate_message'] ?? null);
        } catch (DomainException $e) {
            return $back->with('estimate_error', $e->getMessage())->withInput();
        }

        $told = (int) $ticket->requester_id !== (int) $request->user()->id;

        return $back->with('status', $told
            ? 'Estimated completion date saved. The requester was notified.'
            : 'Estimated completion date saved.');
    }
}
