<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit trail page (Merge Phase M3). READ-ONLY: no create, edit or delete. The only thing that leaves
 * the page is the CSV export of the same filtered list, and that download is itself written to the audit log.
 * Capability: view_audit_logs (maintenance_admin, president) for the page AND the export; everyone else gets a 403.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    private const EXPORT_CHUNK = 500;

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can_do('view_audit_logs'), 403, 'You do not have access to this page.');

        $filters = $this->validated($request);

        $logs = $this->filtered($filters)
            ->with(['user:id,name,email', 'subject'])
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

    /** Same search and filters as the page, but every matching row (not one page). Logged before the file starts. */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can_do('view_audit_logs'), 403, 'You do not have access to this page.');

        $filters = $this->validated($request);
        $query = $this->filtered($filters);
        $rows = (clone $query)->count();

        // Recorded BEFORE the file starts, so even a download that is cancelled half way is on the trail.
        AuditLogger::log(
            AuditLogger::EXPORT,
            sprintf('Exported audit log (CSV): %d %s', $rows, $rows === 1 ? 'entry' : 'entries'),
            user: $request->user(),
            meta: ['report' => 'audit_log', 'format' => 'csv', 'rows' => $rows],
        );

        $query->with('user:id,name,email');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, Csv::BOM);
            Csv::write($out, ['When', 'User', 'Email', 'Action', 'Description', 'IP address']);

            // newest first; the export entry written just above is part of the file only when the filters match it
            $query->chunkByIdDesc(self::EXPORT_CHUNK, function ($logs) use ($out) {
                /** @var AuditLog $log */
                foreach ($logs as $log) {
                    Csv::write($out, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->user?->name ?? ($log->action === 'login_failed' ? 'Unknown' : 'System'),
                        $log->user?->email,
                        $log->action,
                        $log->description,
                        $log->ip_address,
                    ]);
                }
            });

            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** The filters, WITHOUT any ordering (the page orders itself; the export pages by id). */
    private function filtered(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))   // the whole "to" day
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('description', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
                });
            });
    }
}
