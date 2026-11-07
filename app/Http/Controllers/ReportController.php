<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Services\AuditLogger;
use App\Services\ReportMetrics;
use App\Services\TicketActions;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /reports (Merge Phase M4, upgraded in Phase 16): charts + numbers, the "Needs attention" list with quick-assign,
 * and the CSV export of the same filtered list.
 *
 *   view_analytics  -> the page        export_reports -> the CSV      (others get 403, like the settings page)
 *
 * Both only ever use ReportMetrics::tickets(), i.e. MaintenanceRequest::visibleTo($user): confidential
 * tickets are left out for anyone without view_confidential.
 */
class ReportController extends Controller
{
    private const CHUNK = 500;

    public function index(Request $request): View
    {
        $this->authorizeCapability($request, 'view_analytics');
        $filters = $this->filters($request);

        $report = new ReportMetrics($request->user(), $filters['from'], $filters['to']);
        $metrics = $report->all();
        $attention = $report->attention();   // about NOW: the dates above do not apply to it
        $canAssign = (bool) $request->user()->can_do('assign');

        return view('reports.index', [
            'filters' => $filters,
            'metrics' => $metrics,
            'attention' => $attention,
            'canAssign' => $canAssign,
            // only loaded when a quick-assign form can be shown
            'staff' => $canAssign && collect($attention['items'])->contains('unassigned', true)
                ? TicketActions::assignableStaff()
                : collect(),
            'charts' => [
                'status' => $this->series($metrics['by_status']) + ['keys' => array_column($metrics['by_status'], 'key')],
                'category' => $this->series($metrics['by_category']) + ['active' => array_column($metrics['by_category'], 'active')],
                'department' => $this->series($metrics['by_department']),
                'monthly' => $this->series($metrics['monthly']),
                'daily' => $this->series($metrics['daily']),
            ],
            'canExport' => (bool) $request->user()->can_do('export_reports'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeCapability($request, 'export_reports');
        $filters = $this->filters($request);

        $query = (new ReportMetrics($request->user(), $filters['from'], $filters['to']))->tickets();
        $rows = (clone $query)->count();

        // Recorded BEFORE the file starts, so even a download that is cancelled half way is on the trail.
        AuditLogger::log(
            AuditLogger::EXPORT,
            sprintf('Exported tickets report (CSV): %d %s%s', $rows, $rows === 1 ? 'ticket' : 'tickets', $this->rangeLabel($filters)),
            user: $request->user(),
            meta: ['report' => 'tickets', 'format' => 'csv', 'rows' => $rows, 'from' => $filters['from'], 'to' => $filters['to']],
        );

        $query->with(['category:id,name', 'department:id,name', 'requester:id,name', 'assignee:id,name']);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, Csv::BOM);
            Csv::write($out, [
                'Ticket no', 'Status', 'Category', 'Department', 'Requester', 'Assigned to',
                'Emergency', 'Confidential', 'Overdue', 'Location', 'Description',
                'Submitted', 'Due', 'Approved', 'Completed', 'Closed',
            ]);

            $date = fn ($d) => $d?->format('Y-m-d H:i');

            $query->chunkById(self::CHUNK, function ($tickets) use ($out, $date) {
                /** @var MaintenanceRequest $t */
                foreach ($tickets as $t) {
                    Csv::write($out, [
                        $t->ticket_no, $t->status->label(), $t->category?->name, $t->department?->name,
                        $t->requester?->name, $t->assignee?->name,
                        (bool) $t->is_emergency, (bool) $t->is_confidential, $t->isOverdue(),
                        $t->location, $t->description,
                        $date($t->submitted_at), $date($t->due_at), $date($t->president_approved_at), $date($t->completed_at), $date($t->closed_at),
                    ]);
                }
            });

            fclose($out);
        }, 'tickets-report-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------------------------------------

    public function pdf(Request $request)
    {
        $this->authorizeCapability($request, 'export_reports');
        $filters = $this->filters($request);

        $report = new ReportMetrics($request->user(), $filters['from'], $filters['to']);
        $metrics = $report->all();
        $query = $report->tickets();
        $total = (clone $query)->count();
        $limit = 300;

        AuditLogger::log(
            AuditLogger::EXPORT,
            sprintf('Exported tickets report (PDF): %d %s%s', $total, $total === 1 ? 'ticket' : 'tickets', $this->rangeLabel($filters)),
            user: $request->user(),
            meta: ['report' => 'tickets', 'format' => 'pdf', 'rows' => $total, 'from' => $filters['from'], 'to' => $filters['to']],
        );

        $tickets = $query->with(['category:id,name', 'department:id,name', 'requester:id,name', 'assignee:id,name'])
            ->orderByDesc('submitted_at')->limit($limit)->get();

        $logo = null;
        $logoPath = public_path('images/lcct-logo.png');
        if (extension_loaded('gd') && is_file($logoPath)) {
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        $range = match (true) {
            $filters['from'] && $filters['to'] => $filters['from'].' to '.$filters['to'],
            (bool) $filters['from'] => 'from '.$filters['from'],
            (bool) $filters['to'] => 'up to '.$filters['to'],
            default => 'all dates',
        };

        $html = view('reports.pdf', [
            'metrics' => $metrics,
            'tickets' => $tickets,
            'totalTickets' => $total,
            'range' => $range,
            'logo' => $logo,
            'generatedBy' => $request->user()->name,
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', base_path());
        $options->set('fontCache', storage_path('framework/cache'));

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="maintenance-report-'.now()->format('Ymd-Hi').'.pdf"',
        ]);
    }

    private function authorizeCapability(Request $request, string $capability): void
    {
        abort_unless($request->user()?->can_do($capability), 403, 'You do not have access to this page.');
    }

    /** @return array{from: ?string, to: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null];
    }

    private function rangeLabel(array $filters): string
    {
        return match (true) {
            $filters['from'] && $filters['to'] => " submitted {$filters['from']} to {$filters['to']}",
            (bool) $filters['from'] => " submitted from {$filters['from']}",
            (bool) $filters['to'] => " submitted up to {$filters['to']}",
            default => ' (all dates)',
        };
    }

    /** @return array{labels: list<string>, data: list<int>} the shape the chart script reads */
    private function series(array $rows): array
    {
        return [
            'labels' => array_column($rows, 'label'),
            'data' => array_column($rows, 'count'),
        ];
    }
}
