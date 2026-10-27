<?php

namespace App\Mail;

use App\Filament\Resources\CancellationRequests\CancellationRequestResource;
use App\Models\CancellationRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Automation → Cancellation request email): a client asked to cancel a service. */
class CancellationRequestMail extends Mailable
{
    public function __construct(public CancellationRequest $request) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__(':client asked to cancel :service', [
            'client' => $this->request->client->name,
            'service' => $this->request->serviceName(),
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cancellation-request', with: [
            'request' => $this->request,
            'url' => CancellationRequestResource::getUrl(panel: 'admin'),
        ]);
    }
}
