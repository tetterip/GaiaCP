<?php

namespace App\Mail;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Automation → New client email): someone signed up in the client area. */
class NewClientMail extends Mailable
{
    public function __construct(public Client $client, public ?string $provider = null) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__('New client: :client', ['client' => $this->client->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.new-client', with: [
            'client' => $this->client,
            'provider' => $this->provider,
            'url' => ClientResource::getUrl('edit', ['record' => $this->client], panel: 'admin'),
        ]);
    }
}
