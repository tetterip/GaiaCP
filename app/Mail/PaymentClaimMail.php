<?php

namespace App\Mail;

use App\Actions\RecordInvoicePayment;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Payments\PaymentMethods;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Automation → Payment claim email): a client says they paid a notice. */
class PaymentClaimMail extends Mailable
{
    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__(':client says they paid :number by :method', [
            'client' => $this->invoice->client->name,
            'number' => $this->invoice->number,
            'method' => PaymentMethods::label($this->invoice->payment_claim_method),
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-claim', with: [
            'invoice' => $this->invoice,
            'amount' => Money::format(app(RecordInvoicePayment::class)->unpaid($this->invoice)->sum('total_cents'), $this->invoice->currency),
            'method' => PaymentMethods::label($this->invoice->payment_claim_method),
            'url' => InvoiceResource::getUrl('view', ['record' => $this->invoice], panel: 'admin'),
        ]);
    }
}
