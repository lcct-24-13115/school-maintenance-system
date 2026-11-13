<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Writes the audit trail (Merge Phase M3). Call it from the services/listeners that already do the work:
 *
 *   AuditLogger::log(AuditLogger::LOGIN, 'Signed in', user: $user);
 *   AuditLogger::log(AuditLogger::TICKET_STATUS, 'MT-2026-00001: Approved -> Assigned', $ticket, meta: ['status_log_id' => 12]);
 *
 * Rules:
 *  - It NEVER throws. If the row cannot be written the error is reported and the real action carries on.
 *  - It NEVER stores passwords or tokens: sensitive meta keys are dropped and "password=..." style text is redacted.
 *  - Ticket notes and reasons are not copied; point at request_status_logs with meta.status_log_id instead.
 *  - The IP address and user agent come from the current request, so they are empty in artisan commands and seeders.
 */
class AuditLogger
{
    // ---- action names (one place, so the page filter and the tests use the same words) -------------

    public const LOGIN = 'login';

    public const LOGOUT = 'logout';

    public const LOGIN_FAILED = 'login_failed';

    public const REGISTERED = 'registered';

    public const PASSWORD_RESET = 'password_reset';

    public const USER_CREATED = 'user_created';

    public const USER_UPDATED = 'user_updated';

    public const USER_DEACTIVATED = 'user_deactivated';

    public const USER_REACTIVATED = 'user_reactivated';

    public const ROLE_CHANGED = 'role_changed';

    public const ACCOUNT_APPROVED = 'account_approved';   // an admin approved a registration

    public const ACCOUNT_REJECTED = 'account_rejected';   // an admin rejected a registration

    public const USER_PASSWORD_RESET = 'user_password_reset';   // an admin issued a temporary password (Phase 6)

    public const SETTINGS_UPDATED = 'settings_updated';

    public const CATEGORY_CHANGED = 'category_changed';

    // Department management (Phase 5): every change to the department structure is logged.
    public const DEPARTMENT_CREATED = 'department_created';

    public const DEPARTMENT_UPDATED = 'department_updated';

    public const DEPARTMENT_ACTIVATED = 'department_activated';

    public const DEPARTMENT_DEACTIVATED = 'department_deactivated';

    public const DEPARTMENT_DELETED = 'department_deleted';

    public const QR_CODE_CHANGED = 'qr_code_changed';   // a QR code was made, edited or deleted (Phase 17)

    public const EMAIL_RETRIED = 'email_retried';   // an admin sent a failed ticket e-mail again (E-mail log)

    // Help & FAQ manager (Phase 3)
    public const FAQ_CREATED = 'faq_created';

    public const FAQ_UPDATED = 'faq_updated';

    public const FAQ_PUBLISHED = 'faq_published';

    public const FAQ_HIDDEN = 'faq_hidden';

    public const FAQ_REORDERED = 'faq_reordered';

    public const FAQ_DELETED = 'faq_deleted';

    public const TICKET_STATUS = 'ticket_status';

    public const TICKET_ASSIGNED = 'ticket_assigned';

    public const TICKET_REASSIGNED = 'ticket_reassigned';

    public const ATTACHMENT_UPLOADED = 'attachment_uploaded';

    public const ATTACHMENT_DOWNLOADED = 'attachment_downloaded';

    public const ATTACHMENT_DELETED = 'attachment_deleted';   // an uploaded file was removed from a ticket

    public const EXPORT = 'export';

    public const DESCRIPTION_LIMIT = 1000;

    public const USER_AGENT_LIMIT = 255;

    /** Words that mark a meta key or a "word=value" fragment as secret. */
    private const SENSITIVE = ['password', 'passwd', 'token', 'secret', 'authorization', 'cookie'];

    /** @return list<string> every action name, for the page's filter dropdown */
    public static function actions(): array
    {
        return [
            self::LOGIN, self::LOGOUT, self::LOGIN_FAILED, self::REGISTERED, self::PASSWORD_RESET,
            self::USER_CREATED, self::USER_UPDATED, self::USER_DEACTIVATED, self::USER_REACTIVATED, self::ROLE_CHANGED, self::USER_PASSWORD_RESET, self::ACCOUNT_APPROVED, self::ACCOUNT_REJECTED,
            self::SETTINGS_UPDATED, self::CATEGORY_CHANGED, self::QR_CODE_CHANGED, self::EMAIL_RETRIED,
            self::DEPARTMENT_CREATED, self::DEPARTMENT_UPDATED, self::DEPARTMENT_ACTIVATED,
            self::DEPARTMENT_DEACTIVATED, self::DEPARTMENT_DELETED,
            self::FAQ_CREATED, self::FAQ_UPDATED, self::FAQ_PUBLISHED, self::FAQ_HIDDEN, self::FAQ_REORDERED, self::FAQ_DELETED,
            self::TICKET_STATUS, self::TICKET_ASSIGNED, self::TICKET_REASSIGNED,
            self::ATTACHMENT_UPLOADED, self::ATTACHMENT_DOWNLOADED, self::ATTACHMENT_DELETED,
            self::EXPORT,
        ];
    }

    /**
     * Record one event. Returns the row, or null when it could not be written (never throws).
     *
     * @param  ?User  $user  who did it; defaults to the signed-in user. Pass null AND no session for system events.
     * @param  ?array<string, mixed>  $meta  small structured details (ids, old/new values). No secrets.
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        ?User $user = null,
        ?array $meta = null,
    ): ?AuditLog {
        try {
            $user ??= auth()->user();
            $request = request();

            return AuditLog::create([
                'user_id' => $user?->getKey(),
                'action' => mb_substr($action, 0, 50),
                'description' => mb_substr(self::redact($description), 0, self::DESCRIPTION_LIMIT),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'meta' => $meta === null || $meta === [] ? null : self::cleanMeta($meta),
                'ip_address' => $request?->ip(),
                'user_agent' => ($agent = $request?->userAgent()) !== null ? mb_substr($agent, 0, self::USER_AGENT_LIMIT) : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Drop secret keys (at any depth) from the structured details. */
    public static function cleanMeta(array $meta): array
    {
        $clean = [];

        foreach ($meta as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::cleanMeta($value) : $value;
        }

        return $clean;
    }

    /** "password=abc123", "token: xyz" and "Authorization: Bearer xyz" become "password=[redacted]" etc. */
    public static function redact(string $text): string
    {
        $words = implode('|', array_map('preg_quote', self::SENSITIVE));

        return (string) preg_replace(
            '/\b([\w-]*(?:'.$words.')[\w-]*)\b(\s*[:=]\s*)(?:Bearer\s+)?[^\s,;]+/i',
            '$1$2[redacted]',
            $text
        );
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }
}
