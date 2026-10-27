<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for /reports (Merge Phase M4), all from SQL.
 *
 * EVERY query starts from tickets(), which is MaintenanceRequest::visibleTo($user) plus the date range
 * (on the submission date). So a report can never count or list a ticket its viewer may not see:
 * confidential tickets only reach roles with view_confidential.
 *
 * No joins on purpose: visibleTo() uses unqualified column names, and names are looked up afterwards.
 */
class ReportMetrics
{
    public function __construct(
        private readonly User $user,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
    ) {}

    /** The tickets this viewer may see inside the chosen dates. Also used by the CSV export. */
    public function tickets(): Builder
    {
        return MaintenanceRequest::query()
            ->visibleTo($this->user)
            ->when($this->from, fn ($q, $d) => $q->where('submitted_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($this->to, fn ($q, $d) => $q->where('submitted_at', '<=', Carbon::parse($d)->endOfDay()));   // the whole "to" day
    }

    /**
     * @return array{
     *   total: int, emergency: int, overdue: int,
     *   avg_approval_hours: ?float, avg_completion_hours: ?float,
     *   by_status: list<array{key: string, label: string, count: int}>,
     *   by_category: list<array{label: string, count: int}>,
     *   by_department: list<array{label: string, count: int}>,
     *   monthly: list<array{month: string, label: string, count: int}>,
     *   workload: list<array{name: string, assigned: int, open: int, completed: int}>
     * }
     */
    public function all(): array
    {
        return [
            'total' => $this->tickets()->count(),
            'emergency' => $this->tickets()->where('is_emergency', true)->count(),
            'overdue' => $this->tickets()->overdue()->count(),   // the M1 rule: past due_at and still pending
            'avg_approval_hours' => $this->averageHours('submitted_at', 'president_approved_at'),
            'avg_completion_hours' => $this->averageHours('submitted_at', 'completed_at'),
            'by_status' => $this->byStatus(),
            'by_category' => $this->byCategory(),
            'by_department' => $this->byDepartment(),
            'monthly' => $this->monthly(),
            'workload' => $this->workload(),
        ];
    }

    public function byStatus(): array
    {
        $counts = $this->tickets()->toBase()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return collect(TicketStatus::cases())->map(fn (TicketStatus $s) => [
            'key' => $s->value,
            'label' => $s->label(),
            'count' => (int) ($counts[$s->value] ?? 0),
        ])->all();
    }

    public function byCategory(): array
    {
        $counts = $this->tickets()->toBase()->selectRaw('category_id, COUNT(*) as c')->groupBy('category_id')->pluck('c', 'category_id');
        $names = Category::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->map(fn ($c, $id) => ['label' => $names[$id] ?? 'Unknown', 'count' => (int) $c])
            ->sortByDesc('count')->values()->all();
    }

    public function byDepartment(): array
    {
        $counts = $this->tickets()->toBase()->selectRaw('department_id, COUNT(*) as c')->groupBy('department_id')->pluck('c', 'department_id');
        $names = Department::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->map(fn ($c, $id) => ['label' => $id ? ($names[$id] ?? 'Unknown') : 'No department', 'count' => (int) $c])
            ->sortByDesc('count')->values()->all();
    }

    /** Tickets submitted per month, oldest first, with empty months filled in as 0. */
    public function monthly(): array
    {
        $expr = $this->monthExpression();
        $counts = $this->tickets()->toBase()->selectRaw("{$expr} as m, COUNT(*) as c")->groupByRaw($expr)->orderBy('m')->pluck('c', 'm');

        if ($counts->isEmpty()) {
            return [];
        }

        $start = Carbon::parse($counts->keys()->first().'-01');
        $end = Carbon::parse($counts->keys()->last().'-01');
        $rows = [];

        foreach (CarbonPeriod::create($start, '1 month', $end) as $month) {
            $key = $month->format('Y-m');
            $rows[] = ['month' => $key, 'label' => $month->format('M Y'), 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $rows;
    }

    /** Per assigned staff member: everything assigned, still open, and completed (Work Completed or Closed). */
    public function workload(): array
    {
        $open = TicketStatus::pendingValues();
        $done = TicketStatus::completedValues();

        $rows = $this->tickets()->toBase()
            ->whereNotNull('assigned_to')
            ->selectRaw(
                'assigned_to, COUNT(*) as total, '
                .'SUM(CASE WHEN status IN ('.$this->marks($open).') THEN 1 ELSE 0 END) as open_count, '
                .'SUM(CASE WHEN status IN ('.$this->marks($done).') THEN 1 ELSE 0 END) as done_count',
                array_merge($open, $done)
            )
            ->groupBy('assigned_to')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('assigned_to'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->assigned_to] ?? 'Unknown',
            'assigned' => (int) $r->total,
            'open' => (int) $r->open_count,
            'completed' => (int) $r->done_count,
        ])->sortByDesc('assigned')->values()->all();
    }

    /** Average hours between two timestamps of the same ticket, only where both exist. Null when there is no data. */
    public function averageHours(string $start, string $end): ?float
    {
        $row = $this->tickets()->toBase()
            ->whereNotNull($start)->whereNotNull($end)
            ->selectRaw('AVG('.$this->secondsBetween($start, $end).') as s')
            ->first();

        return $row?->s === null ? null : round(((float) $row->s) / 3600, 1);
    }

    // ---- SQL that differs between MySQL (Laragon), SQLite (the tests) and PostgreSQL ------------

    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', submitted_at)",
            'pgsql' => "to_char(submitted_at, 'YYYY-MM')",
            default => "DATE_FORMAT(submitted_at, '%Y-%m')",
        };
    }

    private function secondsBetween(string $start, string $end): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "((julianday({$end}) - julianday({$start})) * 86400)",
            'pgsql' => "EXTRACT(EPOCH FROM ({$end} - {$start}))",
            default => "TIMESTAMPDIFF(SECOND, {$start}, {$end})",
        };
    }

    /** "?, ?, ?" for a list of bindings. */
    private function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
