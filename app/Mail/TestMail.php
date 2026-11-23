<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent from Settings > Email to the signed-in admin to prove the SMTP details work. */
class TestMail extends Mailable
{
    public function __construct(public string $companyName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test email from '.$this->companyName);
    }

    public function content(): Content
    {
        $company = e($this->companyName);

        return new Content(htmlString: "<p>This is a test email from <strong>{$company}</strong>.</p><p>If you can read this, the email settings work.</p>");
    }
}
