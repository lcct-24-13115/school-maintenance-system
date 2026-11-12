<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Account approval: ONE e-mail about ONE registration for ONE person. Built and queued by App\Services\AccountMailer.
 * Carries only plain, ready-made text. $event is account_pending (to the admins), account_approved or
 * account_rejected (to the person); the E-mail log shows it as the "kind".
 */
class AccountMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  subject, headline, intro, name, details, reason, url, button
     */
    public function __construct(public string $event, public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) ($this->data['subject'] ?? 'Account update'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account', with: $this->data);
    }
}
