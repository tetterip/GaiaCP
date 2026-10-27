<?php

namespace App\Mail;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Service;
use App\Support\Money;
use App\Support\ProductOptions;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Automation → New order email): a client placed an order in the client area. */
class NewOrderMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__('New order #:number from :client', ['number' => $this->order->id, 'client' => $this->order->client->name]));
    }

    public function content(): Content
    {
        $this->order->loadMissing('services', 'client');

        return new Content(markdown: 'mail.new-order', with: [
            'order' => $this->order,
            'rows' => $this->order->services->map(fn (Service $service) => [
                trim($service->label.($service->domain && $service->domain !== $service->label ? " ({$service->domain})" : '')
                    .(($options = ProductOptions::describe($service->options, hiddenToo: true)) !== '' ? ' · '.$options : '')),
                $service->billing_cycle?->getLabel(),
                Money::format($service->firstPaymentCents(), $service->currency),
            ])->all(),
            'total' => $this->order->describeTotal(),
            'url' => OrderResource::getUrl('view', ['record' => $this->order], panel: 'admin'),
        ]);
    }
}
