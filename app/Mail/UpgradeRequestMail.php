<?php

namespace App\Mail;

use App\Filament\Resources\UpgradeRequests\UpgradeRequestResource;
use App\Models\UpgradeRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Automation → Upgrade request email): a client asked to change a service's product. */
class UpgradeRequestMail extends Mailable
{
    public function __construct(public UpgradeRequest $request) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__(':client asked to move :service to :product', [
            'client' => $this->request->client->name,
            'service' => $this->request->serviceName(),
            'product' => $this->request->product->name,
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.upgrade-request', with: [
            'request' => $this->request,
            'url' => UpgradeRequestResource::getUrl(panel: 'admin'),
        ]);
    }
}
