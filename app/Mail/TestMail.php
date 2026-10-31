<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A short email admins send to check that mail delivery works.
 */
class TestMail extends Mailable
{
    public function __construct(
        public string $mailerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test email from ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email',
            text: 'email-plain',
            with: [
                'subject' => 'Test email from ' . config('app.name'),
                'lines' => [
                    'Mail delivery works.',
                    "This email was sent with the <b>{$this->mailerName}</b> mailer.",
                ],
            ],
        );
    }
}
