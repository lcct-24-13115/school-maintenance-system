<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Card board for the maintenance staff: big buttons, no typing. */
class MaintenanceBoardController extends Controller
{
    public function index(Request $request): View
    {
        return view('maintenance.board', $this->columns($request));
    }

    /** Just the cards; the page re-fetches this when an alert arrives and every 30 seconds. */
    public function cards(Request $request): View
    {
        return view('maintenance._cards', $this->columns($request));
    }

    public function accept(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'accepted', fn () => TicketWorkflow::startWork($ticket, $request->user()), 'Task accepted. It is yours now.');
    }

    public function complete(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'completed', fn () => TicketWorkflow::markWorkCompleted($ticket, $request->user()), 'Great job! Marked as completed.');
    }

    private function act(Request $request, MaintenanceRequest $ticket, string $event, \Closure $do, string $okMessage): RedirectResponse
    {
        // {ticket} is resolved through visibleTo() (404 if hidden); this checks the "work" capability too.
        Gate::authorize('work', $ticket);

        try {
            $do();
        } catch (DomainException $e) {
            return back()->withErrors(['board' => $e->getMessage()]);
        }

        FormNotifier::work($ticket->refresh(), $request->user(), $event);

        return back()->with('status', $okMessage);
    }

    private function columns(Request $request): array
    {
        $user = $request->user();

        // visibleTo: the board never shows a ticket this user may not see (e.g. confidential ones)
        $base = fn () => MaintenanceRequest::visibleTo($user)
            ->with(['requester', 'department', 'category', 'assignee', 'attachments']);

        return [
            // not started yet (waiting for authorization, approved, or assigned)
            'newTasks' => $base()->whereIn('status', [
                TicketStatus::Submitted->value, TicketStatus::PendingAuthorization->value,
                TicketStatus::Approved->value, TicketStatus::Assigned->value,
            ])->queueOrder()->get(),
            'doingTasks' => $base()->where('status', TicketStatus::InProgress->value)->queueOrder()->get(),
            'doneTasks' => $base()->whereIn('status', TicketStatus::completedValues())
                ->where('completed_at', '>=', now()->subDays(7))
                ->latest('completed_at')->limit(10)->get(),
        ];
    }
}
