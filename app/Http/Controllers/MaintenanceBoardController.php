<?php

namespace App\Http\Controllers;

use App\Models\RepairRequest;
use App\Services\FormNotifier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Card board for the maintenance staff: big buttons, no typing. */
class MaintenanceBoardController extends Controller
{
    public function index(): View
    {
        return view('maintenance.board', $this->columns());
    }

    /** Just the cards; the page re-fetches this when an alert arrives and every 30 seconds. */
    public function cards(): View
    {
        return view('maintenance._cards', $this->columns());
    }

    public function accept(Request $request, RepairRequest $repair): RedirectResponse
    {
        return $this->act($request, $repair, 'accepted', fn () => $repair->accept($request->user()), 'Task accepted. It is yours now.');
    }

    public function complete(Request $request, RepairRequest $repair): RedirectResponse
    {
        return $this->act($request, $repair, 'completed', fn () => $repair->markCompleted($request->user()), 'Great job! Marked as completed.');
    }

    private function act(Request $request, RepairRequest $repair, string $event, \Closure $do, string $okMessage): RedirectResponse
    {
        try {
            $do();
        } catch (DomainException $e) {
            return back()->withErrors(['board' => $e->getMessage()]);
        }

        FormNotifier::work($repair->refresh(), $request->user(), $event);

        return back()->with('status', $okMessage);
    }

    private function columns(): array
    {
        $base = fn () => RepairRequest::with(['requester', 'department', 'assignee'])->where('status', '!=', 'rejected');

        return [
            'newTasks' => $base()->where('work_status', 'pending')->queueOrder()->get(),
            'doingTasks' => $base()->where('work_status', 'accepted')->queueOrder()->get(),
            'doneTasks' => $base()->where('work_status', 'completed')->where('completed_at', '>=', now()->subDays(7))
                ->latest('completed_at')->limit(10)->get(),
        ];
    }
}
