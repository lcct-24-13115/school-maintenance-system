<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Services\AuditLogger;
use App\Services\EmailLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * E-mail log page (Admin). Capability: view_email_log (maintenance_admin only); everyone else gets a 403.
 * Read-only, except "Send again" for a failed TICKET e-mail. Rows cannot be edited or deleted here.
 * The id is read as a plain string and the capability is checked FIRST, so a person without access gets a 403
 * whether or not the row exists (no way to probe ids).
 */
class EmailLogController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $this->authorizeView($request);

        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', [EmailLog::DELIVERED, EmailLog::FAILED, EmailLog::SENDING, EmailLog::UNCONFIRMED])],
            'type' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = EmailLog::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->withEffectiveStatus($status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('to_email', 'like', "%{$term}%")
                        ->orWhere('to_name', 'like', "%{$term}%")
                        ->orWhere('subject', 'like', "%{$term}%")
                        ->orWhere('ticket_no', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $mailer = (string) config('mail.default');

        return view('admin.email-log.index', [
            'logs' => $logs,
            'filters' => $filters,
            'types' => EmailLog::query()->whereNotNull('type')->select('type')->distinct()->orderBy('type')->pluck('type'),
            'counts' => [
                'all' => EmailLog::query()->count(),
                EmailLog::DELIVERED => EmailLog::query()->withEffectiveStatus(EmailLog::DELIVERED)->count(),
                EmailLog::FAILED => EmailLog::query()->withEffectiveStatus(EmailLog::FAILED)->count(),
                EmailLog::UNCONFIRMED => EmailLog::query()->withEffectiveStatus(EmailLog::UNCONFIRMED)->count(),
            ],
            'mailer' => $mailer,
            'fakeMailer' => in_array($mailer, ['log', 'array'], true),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorizeView($request);

        $log = EmailLog::query()->with('user:id,name,email')->findOrFail($id);
        $mailer = (string) config('mail.default');

        return view('admin.email-log.show', [
            'log' => $log,
            'fakeMailer' => in_array($mailer, ['log', 'array'], true),
        ]);
    }

    public function retry(Request $request, string $id): RedirectResponse
    {
        $this->authorizeView($request);

        $log = EmailLog::query()->findOrFail($id);

        if (! $log->canRetry()) {
            return back()->with('error', 'This e-mail cannot be sent again. Only failed ticket e-mails can be re-sent.');
        }

        $ok = EmailLogger::retry($log);

        AuditLogger::log(
            AuditLogger::EMAIL_RETRIED,
            'Re-sent e-mail "'.$log->subject.'" to '.$log->to_email.($ok ? '' : ' (failed again)'),
            user: $request->user(),
            meta: ['email_log_id' => $log->id, 'delivered' => $ok],
        );

        return $ok
            ? back()->with('status', 'The e-mail was sent again.')
            : back()->with('error', 'Sending failed again. See the error on this page.');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->can_do('view_email_log'), 403, 'You do not have access to this page.');
    }
}
