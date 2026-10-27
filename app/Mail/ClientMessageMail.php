<?php

namespace App\Mail;

use App\Enums\ClientMessageKind;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * A message to clients (Clients → Messages to clients) in the client's language, in the client email layout; the
 * client and company placeholders ({$client_first_name}…) are filled in. An informational one ends with the link
 * that turns informational emails off.
 */
class ClientMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ClientMessage $clientMessage, public Client $client)
    {
        $this->locale($client->preferredLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: trim(html_entity_decode(strip_tags(MailTemplates::fill($this->clientMessage->text($this->locale)['subject'], $this->placeholders())), ENT_QUOTES)));
    }

    public function content(): Content
    {
        $body = MailTemplates::fill($this->clientMessage->text($this->locale)['body'], $this->placeholders());

        if ($this->clientMessage->kind === ClientMessageKind::Info && $this->client->exists) {
            $link = URL::signedRoute('unsubscribe', ['client' => $this->client->getKey()]);
            $body .= "\n\n<small>".e(__('You receive our news because you are our client.', [], $this->locale))
                .' <a href="'.e($link).'">'.e(__('Stop informational emails', [], $this->locale)).'</a></small>';
        }

        return new Content(markdown: 'mail.template', with: MailLayout::data($body, $this->placeholders()));
    }

    /** @return array<string, mixed> */
    private function placeholders(): array
    {
        return MailTemplates::common($this->client);
    }
}
