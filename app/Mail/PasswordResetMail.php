<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The Forgot password? email (ResetAdminPassword) in the look of the user's other emails: the company's logo for a
 * client, GaiaCP's for an admin. A Mailable rather than a notification view, so the plain-text part shows the name
 * where the HTML part has the logo.
 */
class PasswordResetMail extends Mailable
{
    // Not a public property: the view data would get the MailMessage, and Laravel would render that with its own views.
    public function __construct(private MailMessage $notice, private bool $client) {}

    /** The notification's texts (greeting, lines, button), as the test reads them. */
    public function notice(): MailMessage
    {
        return $this->notice;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice->subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset', with: ['client' => $this->client] + $this->notice->data());
    }
}
