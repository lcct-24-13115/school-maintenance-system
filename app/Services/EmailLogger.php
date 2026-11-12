<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * E-mail log. Listens to the mailer's own events (registered in AppServiceProvider), so EVERY e-mail is recorded:
 * ticket e-mails, password reset, verification, the test e-mail.
 *
 *   MessageSending -> a row with status "sending"
 *   MessageSent    -> the row becomes "delivered" (with the mail server's message id)
 *   an exception   -> the row becomes "failed" with the error text (see failed())
 *
 * Rules:
 *  - It NEVER throws: a logging problem can never stop an e-mail or the action behind it.
 *  - Only TICKET e-mails keep their body (needed for "Send again"). Password-reset / verification e-mails are
 *    logged without their body, because their link is a secret.
 *  - Error texts go through AuditLogger::redact(), so "password=..." style text is never stored.
 *  - With MAIL_MAILER=log or array, "delivered" only means "written to the log": the page says so.
 */
class EmailLogger
{
    /** Added to the outgoing message so MessageSent / a retry can find the same row. */
    public const HEADER = 'X-Email-Log-Id';

    public const ERROR_LIMIT = 1000;

    /** The row of the e-mail being sent right now in this process (null when none). */
    public static ?int $current = null;

    public static function sending(MessageSending $event): void
    {
        try {
            $message = $event->message;
            $headers = $message->getHeaders();

            // A retry sends the same row again: find it through the header instead of making a second row.
            $existingId = $headers->has(self::HEADER) ? (int) $headers->get(self::HEADER)->getBodyAsString() : 0;
            $log = $existingId > 0 ? EmailLog::query()->find($existingId) : null;

            if ($log !== null) {
                $log->update([
                    'status' => EmailLog::SENDING,
                    'error' => null,
                    'attempts' => (int) $log->attempts + 1,
                    'mailer' => (string) config('mail.default'),
                ]);
            } else {
                $log = EmailLog::create(self::attributes($message, $event->data));
                $headers->addTextHeader(self::HEADER, (string) $log->id);
            }

            self::$current = (int) $log->id;
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function sent(MessageSent $event): void
    {
        try {
            $message = $event->sent->getOriginalMessage();
            $id = 0;

            if ($message instanceof Email && $message->getHeaders()->has(self::HEADER)) {
                $id = (int) $message->getHeaders()->get(self::HEADER)->getBodyAsString();
            }

            $id = $id > 0 ? $id : (int) self::$current;
            self::$current = null;

            if ($id <= 0) {
                return;
            }

            EmailLog::query()->whereKey($id)->update([
                'status' => EmailLog::DELIVERED,
                'error' => null,
                'message_id' => mb_substr((string) $event->sent->getMessageId(), 0, 255) ?: null,
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The mailer threw while sending: mark the e-mail that was being sent as failed. Safe to call twice. */
    public static function failed(Throwable $exception): void
    {
        try {
            $id = self::$current;
            self::$current = null;

            if ($id === null) {
                return;
            }

            EmailLog::query()
                ->whereKey($id)
                ->where('status', EmailLog::SENDING)
                ->update([
                    'status' => EmailLog::FAILED,
                    'error' => mb_substr(AuditLogger::redact(trim($exception->getMessage()) ?: class_basename($exception)), 0, self::ERROR_LIMIT),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Send a failed ticket e-mail again, exactly as it was (same subject, same body), and update the SAME row.
     * Returns true when the mail server accepted it.
     */
    public static function retry(EmailLog $log): bool
    {
        if (! $log->canRetry()) {
            return false;
        }

        try {
            Mail::html((string) $log->body_html, function ($message) use ($log) {
                $message->to($log->to_email, $log->to_name)->subject($log->subject);
                $message->getSymfonyMessage()->getHeaders()->addTextHeader(self::HEADER, (string) $log->id);
            });
        } catch (Throwable $e) {
            self::failed($e);

            return false;
        }

        return $log->fresh()?->status === EmailLog::DELIVERED;
    }

    /** @param  array<string, mixed>  $data  the view data of the e-mail */
    private static function attributes(Email $message, array $data): array
    {
        $to = $message->getTo()[0] ?? null;
        $address = $to?->getAddress() ?? '(unknown)';
        $name = (string) ($to?->getName() ?? '');

        $ticketEmail = isset($data['event'], $data['ticket_no']) && is_string($data['event']);
        $html = $message->getHtmlBody();

        return [
            'status' => EmailLog::SENDING,
            'type' => self::type($data),
            'to_email' => mb_substr($address, 0, 255),
            'to_name' => $name !== '' ? mb_substr($name, 0, 255) : null,
            'user_id' => User::query()->where('email', $address)->value('id'),
            'subject' => mb_substr((string) $message->getSubject(), 0, 255),
            'ticket_no' => isset($data['ticket_no']) ? mb_substr((string) $data['ticket_no'], 0, 40) : null,
            'mailer' => (string) config('mail.default'),
            'body_html' => $ticketEmail && is_string($html) ? $html : null,
        ];
    }

    /** submitted | approved | assigned | reply ... (ticket events), reset_password, verify_email, test, or other. */
    private static function type(array $data): string
    {
        if (isset($data['__laravel_notification']) && is_string($data['__laravel_notification'])) {
            return Str::snake(class_basename($data['__laravel_notification']));
        }

        if (isset($data['event']) && is_string($data['event']) && $data['event'] !== '') {
            return mb_substr($data['event'], 0, 60);
        }

        return array_key_exists('sentAt', $data) ? 'test' : 'other';
    }
}
