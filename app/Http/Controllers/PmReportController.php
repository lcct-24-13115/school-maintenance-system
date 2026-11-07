<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Services\AuditLogger;
use App\Services\PmCompliance;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PM compliance report (PM E3): was the preventive maintenance done on time? The page, a CSV and a PDF.
 *
 *   view_pm_reports (or manage_pm_schedules) opens all three; everyone else gets 403.
 *
 * Only PmCompliance::tickets() is used, i.e. MaintenanceRequest::visibleTo($user), so the report never shows
 * a ticket the person may not see. Downloads are written to the audit log, like the ticket report.
 */
class PmReportController extends Controller
{
    private const PAGE_ROWS = 50;

    private const PDF_ROWS = 300;

    public function index(Request $request): View
    {
        $this->authorizeView($request);
        $filters = $this->filters($request);

        $report = $this->report($request, $filters)->analyse();

        return view('pm-reports.index', [
            'filters' => $filters,
            'summary' => $report['summary'],
            'byFacility' => $report['by_facility'],
            'byMonth' => $report['by_month'],
            'overdue' => array_slice($report['overdue'], 0, 25),
            'overdueTotal' => count($report['overdue']),
            'rows' => array_slice($report['rows'], 0, self::PAGE_ROWS),
            'rowsTotal' => count($report['rows']),
            'pageRows' => self::PAGE_ROWS,
            'facilities' => Facility::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'labels' => PmCompliance::labels(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeView($request);
        $filters = $this->filters($request);

        $rows = $this->report($request, $filters)->analyse()['rows'];
        $this->audit($request, 'CSV', count($rows), $filters);

        $labels = PmCompliance::labels();

        return response()->streamDownload(function () use ($rows, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, Csv::BOM);
            Csv::write($out, [
                'Ticket no', 'PM schedule', 'Facility', 'Equipment', 'Assigned to', 'Technician', 'Ticket status',
                'PM due date', 'Completed date', 'Result', 'Days late / overdue',
            ]);

            foreach ($rows as $r) {
                Csv::write($out, [
                    $r['ticket_no'], $r['schedule'], $r['facility'], $r['equipment'], $r['assignee'], $r['technician'], $r['status'],
                    $r['due'], $r['done'], $labels[$r['bucket']], in_array($r['bucket'], ['late', 'overdue'], true) ? $r['days'] : '',
                ]);
            }

            fclose($out);
        }, 'pm-compliance-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request)
    {
        $this->authorizeView($request);
        $filters = $this->filters($request);

        $report = $this->report($request, $filters)->analyse();
        $this->audit($request, 'PDF', count($report['rows']), $filters);

        $logo = null;
        $logoPath = public_path('images/lcct-logo.png');
        if (extension_loaded('gd') && is_file($logoPath)) {
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        $html = view('pm-reports.pdf', [
            'summary' => $report['summary'],
            'byFacility' => $report['by_facility'],
            'byMonth' => $report['by_month'],
            'overdue' => array_slice($report['overdue'], 0, 25),
            'overdueTotal' => count($report['overdue']),
            'rows' => array_slice($report['rows'], 0, self::PDF_ROWS),
            'rowsTotal' => count($report['rows']),
            'labels' => PmCompliance::labels(),
            'range' => $this->rangeText($filters),
            'facilityName' => $filters['facility_id'] ? Facility::whereKey($filters['facility_id'])->value('name') : null,
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
            'Content-Disposition' => 'attachment; filename="pm-compliance-'.now()->format('Ymd-Hi').'.pdf"',
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->canViewPmReports(), 403, 'You do not have access to this page.');
    }

    /** @param  array{from: ?string, to: ?string, facility_id: ?int}  $filters */
    private function report(Request $request, array $filters): PmCompliance
    {
        return new PmCompliance($request->user(), $filters['from'], $filters['to'], $filters['facility_id']);
    }

    /** @return array{from: ?string, to: ?string, facility_id: ?int} dates are PM DUE dates */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
        ]);

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'facility_id' => filled($data['facility_id'] ?? null) ? (int) $data['facility_id'] : null,
        ];
    }

    private function rangeText(array $filters): string
    {
        return match (true) {
            $filters['from'] && $filters['to'] => $filters['from'].' to '.$filters['to'],
            (bool) $filters['from'] => 'from '.$filters['from'],
            (bool) $filters['to'] => 'up to '.$filters['to'],
            default => 'all dates',
        };
    }

    private function audit(Request $request, string $format, int $rows, array $filters): void
    {
        // Recorded BEFORE the file is sent, so even a download that is cancelled half way is on the trail.
        AuditLogger::log(
            AuditLogger::EXPORT,
            sprintf('Exported PM compliance report (%s): %d %s (PM due %s)', $format, $rows, $rows === 1 ? 'ticket' : 'tickets', $this->rangeText($filters)),
            user: $request->user(),
            meta: ['report' => 'pm_compliance', 'format' => strtolower($format), 'rows' => $rows, 'from' => $filters['from'], 'to' => $filters['to'], 'facility_id' => $filters['facility_id']],
        );
    }
}
