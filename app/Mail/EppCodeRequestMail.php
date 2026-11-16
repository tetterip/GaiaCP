<?php

namespace App\Mail;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the admins (Receive system emails, area Services): a client asked for the EPP code of a domain at a registrar
 * whose API gives none (GivesNoEppCode), so an admin reads it there and sends it with Send EPP code.
 */
class EppCodeRequestMail extends Mailable
{
    public function __construct(public Service $service) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__(':client asked for the EPP code of :domain', [
            'client' => $this->service->client->name,
            'domain' => $this->service->domain,
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.epp-code-request', with: [
            'service' => $this->service,
            'registrar' => $this->service->registration()->registrarAccount()->name ?? '',
            'url' => ServiceResource::getUrl('edit', ['record' => $this->service], panel: 'admin'),
        ]);
    }
}
