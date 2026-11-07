<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PmSchedule;
use App\Services\PmTicketGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/** The "Ticket now" button: makes the ticket for the schedule's next due date right away (for testing or an early job). */
class PmScheduleGenerateController extends Controller
{
    public function __invoke(Request $request, PmSchedule $pmSchedule): RedirectResponse
    {
        abort_unless($request->user()?->can_do('manage_pm_schedules'), 403, 'You do not have access to this page.');

        if (! $pmSchedule->is_active) {
            return back()->with('error', "Activate \"{$pmSchedule->title}\" first.");
        }

        if ($pmSchedule->next_due_date === null) {
            return back()->with('error', "\"{$pmSchedule->title}\" has ended, so it has no next due date.");
        }

        try {
            [$outcome, $ticket] = PmTicketGenerator::generate($pmSchedule, null, true, $request->user());
        } catch (Throwable $e) {
            report($e);
            PmTicketGenerator::logRun('manual', $request->user(), 1, 0, 0, ["{$pmSchedule->title}: {$e->getMessage()}"]);

            return back()->with('error', 'The ticket could not be made: '.$e->getMessage());
        }

        $errors = $outcome === 'no_requester' ? ["{$pmSchedule->title}: there is no active maintenance staff to file the ticket."] : [];
        PmTicketGenerator::logRun('manual', $request->user(), 1, $outcome === 'created' ? 1 : 0, $outcome === 'created' ? 0 : 1, $errors);

        return match ($outcome) {
            'created' => back()->with('status', "Ticket {$ticket->ticket_no} was made for \"{$pmSchedule->title}\". The schedule moved to its next date."),
            'exists' => back()->with('status', "A ticket for that due date already exists ({$ticket->ticket_no}). The schedule moved to its next date."),
            'no_requester' => back()->with('error', 'There is no active maintenance staff to file the ticket.'),
            default => back()->with('error', "Nothing was made for \"{$pmSchedule->title}\"."),
        };
    }
}