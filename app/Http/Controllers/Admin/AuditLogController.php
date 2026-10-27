<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The audit trail page (Merge Phase M3). READ-ONLY: no create, edit, delete or export.
 * Capability: view_audit_logs (maintenance_admin, president); everyone else gets a 403.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can_do('view_audit_logs'), 403, 'You do not have access to this page.');

        $filters = $request->validate([
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with(['user:id,name,email', 'subject'])
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))   // the whole "to" day
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('description', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // A row links to its ticket only when THIS viewer is allowed to see that ticket (TicketPolicy@view).
        $viewer = $request->user();
        $ticketLinks = [];
        foreach ($logs as $log) {
            if ($log->subject instanceof MaintenanceRequest && $viewer->can('view', $log->subject)) {
                $ticketLinks[$log->id] = route('tickets.show', $log->subject);
            }
        }

        return view('admin.audit-logs', [
            'logs' => $logs,
            'ticketLinks' => $ticketLinks,
            'filters' => $filters,
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()
                ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())
                ->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
