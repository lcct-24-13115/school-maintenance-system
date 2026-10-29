<?php

namespace App\Console\Commands;

use App\Mail\TestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Phase 1: `php artisan mail:test someone@example.com`
 * Sends one branded email right now (not queued) so you can see whether Brevo / SMTP works.
 * Never prints the SMTP password.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {email : Where to send the test message}';

    protected $description = 'Send a test email to check the mail settings (Brevo SMTP)';

    public function handle(): int
    {
        $to = (string) $this->argument('email');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"$to\" is not a valid email address.");

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $cfg = config("mail.mailers.$mailer", []);

        $this->line('Mailer : '.$mailer);
        if (($cfg['transport'] ?? null) === 'smtp') {
            $this->line('Server : '.($cfg['host'] ?? '?').':'.($cfg['port'] ?? '?'));
            $this->line('Login  : '.(($cfg['username'] ?? '') !== '' ? $cfg['username'] : '(empty)'));
        }
        $this->line('From   : '.config('mail.from.address').' <'.config('mail.from.name').'>');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is \"$mailer\": nothing is really delivered. Set MAIL_MAILER=smtp in .env for real emails.");
        }

        try {
            Mail::to($to)->send(new TestMail);
        } catch (Throwable $e) {
            $this->error('Could not send: '.$e->getMessage());
            $this->line('Check MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD, and that MAIL_FROM_ADDRESS is a verified sender in Brevo.');

            return self::FAILURE;
        }

        $this->info("Sent to $to".($mailer === 'log' ? ' (written to storage/logs/laravel.log).' : '. Check the inbox and spam folder.'));

        return self::SUCCESS;
    }
}
