<?php

namespace App\Mail;

use App\Enums\MailTemplate;
use App\Models\Client;
use App\Models\Ticket;
use App\Support\Locales;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/** Any email to a client: the template's text from the Email templates page with the placeholders filled in. */
class ClientMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array{subject: string, body: string}|null unsaved text to render instead (preview, or a one-off email the admin edited) */
    public ?array $previewText = null;

    /** The "View in the client area" button's link (ClientAreaLinks), none without one. */
    public ?string $clientAreaUrl = null;

    /** The button's text for clientAreaUrl, in the email's language; null = View in the client area. */
    public ?string $linkLabel = null;

    /** The ticket it is about: its replies come back to the ticket (thread headers, Reply-To). */
    public ?Ticket $ticket = null;

    /** @var list<array{name: string, data: string, mime: string}> files sent with it, e.g. an invoice's PDF */
    public array $files = [];

    /** @param  array<string, string|HtmlString|null>  $values  the template's own placeholders */
    public function __construct(
        public MailTemplate $mailTemplate,
        public Client $client,
        public array $values = [],
    ) {
        // Rendered in the client's language (Mailable::$locale): its template text and any __() in the layout.
        $this->locale($client->preferredLocale());
    }

    /** An example of the template for the preview, with a made-up client; the tables' texts in that language. */
    public static function sample(MailTemplate $template, ?string $locale = null): self
    {
        if (in_array($template, [MailTemplate::RenewalReminder, MailTemplate::OverdueNotice, MailTemplate::DomainRenewalNotice], true)) {
            return RenewalReminderMail::sample($template, $locale);
        }

        $values = Locales::using($locale, function () use ($template) {
            $values = ['billing_cycle' => __('Annually')] + $template->sampleValues();
            $values['nameservers'] = new HtmlString(nl2br(e($values['nameservers']), false));
            $values['order_items'] = MailTemplates::table([__('Item'), __('Billing cycle'), __('Amount')], [['Domain example.gr', __('Annually'), '€15.00'], ['Starter (2.5GB) example.gr', __('Annually'), '€80.00']]);
            $values['price_changes'] = MailTemplates::table([__('Service'), __('Billing cycle'), __('Current price'), __('New price')], [['Starter (2.5GB) example.gr', __('Annually'), '€80.00', '€90.00'], ['Domain example.gr', __('Annually'), '€15.00', '€18.00']]);
            $values['quote_items'] = MailTemplates::table([__('Item'), __('Billing cycle'), __('First payment'), __('Renews at')], [['Starter (2.5GB) example.gr', __('Annually'), '€80.00', '€80.00'], ['example.gr · '.__('Register'), __('Annually'), '€15.00', '€15.00'], [__('Moving your website'), '', '€50.00', '']]);
            $values['usage'] = MailTemplates::table([__('Usage'), __('Used'), __('Limit'), __('Share')], [[__('Disk space'), '2.2 GB', '2.5 GB', '88%']]);
            $values['invoice_items'] = MailTemplates::table([__('Description'), __('Period'), __('Amount')], [['Starter (2.5GB) example.gr', '09/10/2026 - 08/10/2027', '€80.00'], ['Domain renewal example.gr', '09/10/2026 - 08/10/2027', '€15.00']]);

            return $values;
        });

        $mail = new self($template, self::sampleClient(), $values);
        $mail->clientAreaUrl = $template->linksToClientArea() && config('gaiacp.client_area.email_links') ? 'https://clients.example.gr/' : null;

        return $mail;
    }

    public static function sampleClient(): Client
    {
        return Client::make(['first_name' => 'Γιώργος', 'last_name' => 'Παπαδόπουλος', 'company' => 'Example ΙΚΕ', 'email' => 'client@example.gr']);
    }

    public function template(): MailTemplate
    {
        return $this->mailTemplate;
    }

    public function envelope(): Envelope
    {
        $replyTo = $this->ticket ? ($this->ticket->department?->email ?: config('gaiacp.support.reply_to')) : null;

        return new Envelope(subject: $this->rendered()['subject'], replyTo: filled($replyTo) ? [$replyTo] : []);
    }

    public function headers(): Headers
    {
        if ($this->ticket === null) {
            return new Headers;
        }

        return new Headers(messageId: $this->ticket->newEmailMessageId(), references: [$this->ticket->emailThreadId()]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.template', with: MailLayout::data($this->rendered()['body'], $this->placeholders()) + ['clientAreaUrl' => $this->clientAreaUrl, 'linkLabel' => $this->linkLabel]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (array $file) => Attachment::fromData(fn () => $file['data'], $file['name'])->withMime($file['mime']), $this->files);
    }

    /** @return array<string, string|HtmlString|null> */
    public function placeholders(): array
    {
        return MailTemplates::common($this->client) + $this->values;
    }

    /** @return array{subject: string, body: string} */
    private function rendered(): array
    {
        return MailTemplates::render($this->template(), $this->placeholders(), $this->previewText, $this->locale);
    }
}
