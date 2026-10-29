<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Phase 1: the message sent by `php artisan mail:test`. Proves the mail settings work. */
class TestMail extends Mailable
{
    public function __construct(public string $sentAt = '')
    {
        $this->sentAt = $sentAt ?: now()->format('M j, Y g:i A');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test email from '.config('mail.brand.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.test');
    }
}
