<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Phase 13: ONE email about ONE ticket event for ONE person. Built and queued by App\Services\TicketMailer.
 *
 * It carries only plain, ready-made text (no models), decided at queue time: the queue worker needs no database
 * lookup, a later change to the ticket cannot alter an email already waiting, and the confidential-ticket rule is
 * applied in one place. $event is the kind (submitted, approved, assigned, reply, escalated ...), used by the tests.
 */
class TicketNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  subject, headline, intro, name, ticket_no, status, details, note, url, confidential
     */
    public function __construct(public string $event, public array $data)
    {
        // Deliberately NOT afterCommit(): with QUEUE_CONNECTION=sync the email would then be built when the outer
        // transaction commits, outside TicketMailer's try/catch, and a broken email could stop the ticket action.
        // With the database queue the job row is written in the same transaction anyway, so a rolled-back change
        // takes its email with it.
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) ($this->data['subject'] ?? 'Ticket update'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket', with: $this->data);
    }
}
