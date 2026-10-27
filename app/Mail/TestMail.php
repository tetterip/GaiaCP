<?php

namespace App\Mail;

use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Settings → Mail → Send test email: checks the SMTP login and shows the logo, header and footer. */
class TestMail extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Test email from :name', ['name' => config('gaiacp.company.name') ?: config('app.name')]));
    }

    public function content(): Content
    {
        $body = __('This is a test email from GaiaCP.')."\n\n".__("If you can read it, the mail settings work. Client emails look like this one: logo, header, the template's text, footer.");

        return new Content(markdown: 'mail.template', with: MailLayout::data($body, MailTemplates::common(ClientMail::sampleClient())));
    }
}
