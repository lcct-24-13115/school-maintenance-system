<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GET /approvals - the authorizer's inbox: tickets waiting for authorization (Pending Authorization, plus
 * Submitted when school.workflow.authorize_from_submitted), emergencies first, then oldest first.
 * Approve / Disapprove post to TicketActionController. Dept. heads, treasurer and custodian stay view-only.
 */
class ApprovalController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canAuthorize(), 403, 'You do not have access to this page.');

        $tickets = MaintenanceRequest::query()
            ->visibleTo($user)
            ->awaitingAuthorization()
            ->with(['requester', 'department', 'category'])
            ->queueOrder()   // emergencies on top, then ORIGINAL submission time (oldest first)
            ->paginate(self::PER_PAGE);

        return view('approvals.index', ['tickets' => $tickets]);
    }
}
