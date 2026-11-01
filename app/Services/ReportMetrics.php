<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for /reports (Merge Phase M4, upgraded in Phase 16), all from SQL.
 *
 * EVERY query starts from visibleTickets(), which is MaintenanceRequest::visibleTo($user). So a report can
 * never count or list a ticket its viewer may not see: confidential tickets only reach roles with
 * view_confidential. tickets() adds the date range (on the submission date); the "Needs attention" list uses
 * visibleTickets() directly, because it is about NOW and not about a period.
 *
 * Definitions (CONFIRM WITH CLIENT, see docs/OPEN_QUESTIONS.md rows 53-60):
 *   backlog / active   still pending: TicketStatus::pendingValues() (Work Completed is NOT backlog: the work is done)
 *   resolved           Work Completed or Closed
 *   resolution rate    resolved / (all tickets - Cancelled). Disapproved tickets stay in the bottom line.
 *   first response     submitted_at -> the first history line or PUBLIC message by someone other than the requester
 *                      (automatic actions with no user, and internal notes, never count, so every viewer sees the same number)
 *   overdue            MaintenanceRequest::scopeOverdue(): past due_at, still pending, not Waiting for User
 *   unassigned         no assignee, in a status where a ticket can be assigned (TicketWorkflow::assignableStatuses())
 *   stale              pending, not Waiting for User, and no activity for school.reports.stale_days (default 3) days
 *
 * No joins on purpose: visibleTo() uses unqualified column names, and names are looked up afterwards.
 */
class ReportMetrics
{
    private const CHUNK = 200;

    public function __construct(
        private readonly User $user,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
    ) {}

    /** Everything this viewer may see, whatever the dates. */
    public function visibleTickets(): Builder
    {
        return MaintenanceRequest::query()->visibleTo($this->user);
    }

    /** The tickets this viewer may see inside the chosen dates. Also used by the CSV export. */
    public function tickets(): Builder
    {
        return $this->visibleTickets()
            ->when($this->from, fn ($q, $d) => $q->where('submitted_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($this->to, fn ($q, $d) => $q->where('submitted_at', '<=', Carbon::parse($d)->endOfDay()));   // the whole "to" day
    }

    /**
     * @return array{
     *   total: int, emergency: int, overdue: int, backlog: int, cancelled: int, resolved: int,
     *   resolution_rate: ?float, avg_first_response_hours: ?float, first_response_count: int,
     *   avg_approval_hours: ?float, avg_completion_hours: ?float,
     *   by_status: list<array{key: string, label: string, count: int}>,
     *   by_category: list<array{label: string, count: int, active: int}>,
     *   by_department: list<array{label: string, count: int}>,
     *   monthly: list<array{month: string, label: string, count: int}>,
     *   daily: list<array{day: string, label: string, count: int}>,
     *   workload: list<array{name: string, assigned: int, open: int, completed: int, overdue: int}>
     * }
     */
    public function all(): array
    {
        $statusCounts = $this->statusCounts();
        $total = (int) $statusCounts->sum();
        $cancelled = (int) ($statusCounts[TicketStatus::Cancelled->value] ?? 0);
        $resolved = (int) collect(TicketStatus::completedValues())->sum(fn ($v) => $statusCounts[$v] ?? 0);
        $backlog = (int) collect(TicketStatus::pendingValues())->sum(fn ($v) => $statusCounts[$v] ?? 0);
        $firstResponse = $this->firstResponse();

        return [
            'total' => $total,
            'emergency' => $this->tickets()->where('is_emergency', true)->count(),
            'overdue' => $this->tickets()->overdue()->count(),   // the M1 rule: past due_at and still pending
            'backlog' => $backlog,
            'cancelled' => $cancelled,
            'resolved' => $resolved,
            'resolution_rate' => $this->resolutionRate($resolved, $total, $cancelled),
            'avg_first_response_hours' => $firstResponse['avg_hours'],
            'first_response_count' => $firstResponse['answered'],
            'avg_approval_hours' => $this->averageHours('submitted_at', 'president_approved_at'),
            'avg_completion_hours' => $this->averageHours('submitted_at', 'completed_at'),
            'by_status' => $this->byStatus($statusCounts),
            'by_category' => $this->byCategory(),
            'by_department' => $this->byDepartment(),
            'monthly' => $this->monthly(),
            'daily' => $this->daily(),
            'workload' => $this->workload(),
        ];
    }

    /** status value => number of tickets. */
    public function statusCounts(): Collection
    {
        return $this->tickets()->toBase()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
    }

    public function byStatus(?Collection $counts = null): array
    {
        $counts ??= $this->statusCounts();

        return collect(TicketStatus::cases())->map(fn (TicketStatus $s) => [
            'key' => $s->value,
            'label' => $s->label(),
            'count' => (int) ($counts[$s->value] ?? 0),
        ])->all();
    }

    /** Resolved / (all - cancelled), in percent with one decimal. Null when nothing is left to count. */
    public function resolutionRate(int $resolved, int $total, int $cancelled): ?float
    {
        $base = $total - $cancelled;

        return $base > 0 ? round($resolved / $base * 100, 1) : null;
    }

    /** Per category: every ticket ("count") and the ones still open ("active", the same rule as the backlog). */
    public function byCategory(): array
    {
        $counts = $this->tickets()->toBase()->selectRaw('category_id, COUNT(*) as c')->groupBy('category_id')->pluck('c', 'category_id');
        $active = $this->tickets()->pending()->toBase()->selectRaw('category_id, COUNT(*) as c')->groupBy('category_id')->pluck('c', 'category_id');
        $names = Category::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->map(fn ($c, $id) => [
            'label' => $names[$id] ?? 'Unknown',
            'count' => (int) $c,
            'active' => (int) ($active[$id] ?? 0),
        ])->sortByDesc('count')->values()->all();
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

    /**
     * Tickets submitted per day, with empty days filled in as 0. Covers school.reports.daily_days days (30) ending on the
     * "to" date (today when there is none); a "from" date inside that window shortens it. Empty list when the window is empty.
     */
    public function daily(): array
    {
        $days = max(1, (int) config('school.reports.daily_days', 30));
        $end = ($this->to ? Carbon::parse($this->to) : now())->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        if ($this->from && ($from = Carbon::parse($this->from)->startOfDay())->gt($start)) {
            $start = $from;
        }

        if ($start->gt($end)) {
            return [];
        }

        $expr = $this->dayExpression();
        $counts = $this->tickets()
            ->where('submitted_at', '>=', $start)
            ->where('submitted_at', '<=', $end->copy()->endOfDay())
            ->toBase()
            ->selectRaw("{$expr} as d, COUNT(*) as c")
            ->groupByRaw($expr)
            ->pluck('c', 'd');

        $rows = [];

        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $key = $day->format('Y-m-d');
            $rows[] = ['day' => $key, 'label' => $day->format('M j'), 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $rows;
    }

    /** Per assigned staff member: everything assigned, still open, completed (Work Completed or Closed) and overdue now. */
    public function workload(): array
    {
        $open = TicketStatus::pendingValues();
        $done = TicketStatus::completedValues();
        $overdueStatuses = array_values(array_diff($open, [TicketStatus::WaitingForUser->value]));   // the SLA clock is paused while waiting

        $rows = $this->tickets()->toBase()
            ->whereNotNull('assigned_to')
            ->selectRaw(
                'assigned_to, COUNT(*) as total, '
                .'SUM(CASE WHEN status IN ('.$this->marks($open).') THEN 1 ELSE 0 END) as open_count, '
                .'SUM(CASE WHEN status IN ('.$this->marks($done).') THEN 1 ELSE 0 END) as done_count, '
                .'SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? AND status IN ('.$this->marks($overdueStatuses).') THEN 1 ELSE 0 END) as overdue_count',
                array_merge($open, $done, [now()->toDateTimeString()], $overdueStatuses)   // same order as the ? marks above
            )
            ->groupBy('assigned_to')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('assigned_to'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->assigned_to] ?? 'Unknown',
            'assigned' => (int) $r->total,
            'open' => (int) $r->open_count,
            'completed' => (int) $r->done_count,
            'overdue' => (int) $r->overdue_count,
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

    /**
     * Average time to first response. A ticket counts once somebody OTHER than its requester has acted on it:
     * the first history line (status change, assignment, authorization, note, flag, file) or the first PUBLIC message.
     * Lines with no user (automatic escalation, auto-close) and internal notes do not count. Tickets nobody has
     * answered yet are left out of the average (they are in "of").
     *
     * @return array{avg_hours: ?float, answered: int, of: int}
     */
    public function firstResponse(): array
    {
        $seconds = 0;
        $answered = 0;
        $of = 0;

        $this->tickets()->select(['id', 'requester_id', 'submitted_at'])->orderBy('id')
            ->chunkById(self::CHUNK, function ($tickets) use (&$seconds, &$answered, &$of) {
                $ids = $tickets->pluck('id')->all();
                $first = [];   // ticket id => timestamp of the first response

                $logs = DB::table('request_status_logs')->whereIn('request_id', $ids)->whereNotNull('user_id')
                    ->get(['request_id', 'user_id', 'created_at']);
                $replies = DB::table('ticket_replies')->whereIn('request_id', $ids)->whereNotNull('user_id')->where('is_internal', false)
                    ->get(['request_id', 'user_id', 'created_at']);

                $byTicket = $tickets->keyBy('id');

                foreach ($logs->concat($replies) as $row) {
                    $ticket = $byTicket[$row->request_id] ?? null;

                    if ($ticket === null || (int) $row->user_id === (int) $ticket->requester_id) {
                        continue;
                    }

                    $at = Carbon::parse($row->created_at)->getTimestamp();
                    $first[$row->request_id] = isset($first[$row->request_id]) ? min($first[$row->request_id], $at) : $at;
                }

                foreach ($tickets as $ticket) {
                    $of++;

                    if (isset($first[$ticket->id]) && $ticket->submitted_at !== null) {
                        $answered++;
                        $seconds += max(0, $first[$ticket->id] - $ticket->submitted_at->getTimestamp());
                    }
                }
            });

        return [
            'avg_hours' => $answered > 0 ? round($seconds / $answered / 3600, 1) : null,
            'answered' => $answered,
            'of' => $of,
        ];
    }

    /**
     * "Needs attention": open tickets that are overdue, unassigned or stale, whatever the dates (it is about NOW).
     * Only tickets this viewer may see. Worst first: emergency, then most reasons, then the oldest target date.
     *
     * @return array{total: int, items: list<array{ticket: MaintenanceRequest, reasons: list<array{key: string, label: string}>, unassigned: bool}>}
     */
    public function attention(?int $limit = null): array
    {
        $limit ??= max(1, (int) config('school.reports.attention_limit', 25));
        $staleDays = max(1, (int) config('school.reports.stale_days', 3));
        $now = now();
        $cutoff = $now->copy()->subDays($staleDays);
        $assignable = array_map(fn (TicketStatus $s) => $s->value, TicketWorkflow::assignableStatuses());
        $waiting = TicketStatus::WaitingForUser->value;

        $flagged = [];   // ticket id => ['reasons' => [...], 'sort' => [...]]

        $this->visibleTickets()->pending()
            ->where(function (Builder $q) use ($assignable, $waiting, $cutoff) {
                $q->where(fn (Builder $o) => $o->overdue())
                    ->orWhere(fn (Builder $u) => $u->whereNull('assigned_to')->whereIn('status', $assignable))
                    ->orWhere(fn (Builder $s) => $s->where('status', '!=', $waiting)->where('updated_at', '<', $cutoff));
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($tickets) use (&$flagged, $assignable, $waiting, $cutoff, $now) {
                $lastActivity = $this->lastActivity($tickets->filter(
                    fn (MaintenanceRequest $t) => $t->status->value !== $waiting && $t->updated_at !== null && $t->updated_at->lt($cutoff)
                ));

                /** @var MaintenanceRequest $t */
                foreach ($tickets as $t) {
                    $reasons = [];

                    if ($t->isOverdue()) {
                        $reasons[] = ['key' => 'overdue', 'label' => 'Overdue by '.$this->span($t->due_at, $now)];
                    }

                    if ($t->assigned_to === null && in_array($t->status->value, $assignable, true)) {
                        $reasons[] = ['key' => 'unassigned', 'label' => 'Unassigned'];
                    }

                    if (isset($lastActivity[$t->id]) && $lastActivity[$t->id]->lt($cutoff)) {
                        $reasons[] = ['key' => 'stale', 'label' => 'No activity for '.$this->span($lastActivity[$t->id], $now)];
                    }

                    if ($reasons !== []) {
                        $flagged[$t->id] = [
                            'reasons' => $reasons,
                            'emergency' => (int) $t->is_emergency,
                            'due' => $t->due_at?->getTimestamp() ?? PHP_INT_MAX,
                        ];
                    }
                }
            });

        uasort($flagged, fn ($a, $b) => [$b['emergency'], count($b['reasons']), $a['due']] <=> [$a['emergency'], count($a['reasons']), $b['due']]);

        $topIds = array_slice(array_keys($flagged), 0, $limit);
        $models = $this->visibleTickets()->whereIn('id', $topIds)
            ->with(['category:id,name', 'assignee:id,name', 'requester:id,name'])
            ->get()->keyBy('id');

        $items = [];

        foreach ($topIds as $id) {
            if (! isset($models[$id])) {
                continue;
            }

            $items[] = [
                'ticket' => $models[$id],
                'reasons' => $flagged[$id]['reasons'],
                'unassigned' => collect($flagged[$id]['reasons'])->contains('key', 'unassigned'),
            ];
        }

        return ['total' => count($flagged), 'items' => $items];
    }

    /**
     * ticket id => when anything last happened on it: the ticket itself, its history, its public messages and, only
     * for a viewer who may read internal notes on THAT ticket (TicketPolicy@viewInternalNotes), its internal notes.
     * So the hidden notes can never change what a viewer without access sees.
     *
     * @param  Collection<int, MaintenanceRequest>  $tickets
     * @return array<int, Carbon>
     */
    private function lastActivity(Collection $tickets): array
    {
        if ($tickets->isEmpty()) {
            return [];
        }

        $ids = $tickets->pluck('id')->all();
        $logs = DB::table('request_status_logs')->whereIn('request_id', $ids)
            ->selectRaw('request_id, MAX(created_at) as last_at')->groupBy('request_id')->pluck('last_at', 'request_id');
        $public = DB::table('ticket_replies')->whereIn('request_id', $ids)->where('is_internal', false)
            ->selectRaw('request_id, MAX(created_at) as last_at')->groupBy('request_id')->pluck('last_at', 'request_id');
        $internal = DB::table('ticket_replies')->whereIn('request_id', $ids)->where('is_internal', true)
            ->selectRaw('request_id, MAX(created_at) as last_at')->groupBy('request_id')->pluck('last_at', 'request_id');

        $out = [];

        foreach ($tickets as $t) {
            $times = array_filter([$t->updated_at, $logs[$t->id] ?? null, $public[$t->id] ?? null]);

            if (isset($internal[$t->id]) && $this->user->can('viewInternalNotes', $t)) {
                $times[] = $internal[$t->id];
            }

            $out[$t->id] = collect($times)->map(fn ($x) => Carbon::parse($x))->max();
        }

        return $out;
    }

    /** "2 d", "5 h", "20 min": how long between $a and $b, short. */
    private function span(Carbon $a, Carbon $b): string
    {
        $seconds = abs($b->getTimestamp() - $a->getTimestamp());

        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400).' d',
            $seconds >= 3600 => intdiv($seconds, 3600).' h',
            default => max(1, intdiv($seconds, 60)).' min',
        };
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

    private function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', submitted_at)",
            'pgsql' => "to_char(submitted_at, 'YYYY-MM-DD')",
            default => "DATE_FORMAT(submitted_at, '%Y-%m-%d')",
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
