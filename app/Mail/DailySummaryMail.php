<?php

namespace App\Mail;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\ActivityLog;
use App\Providers\AppServiceProvider;
use App\Reports\DailySummary;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins who want it (Admin users → Receive system emails; Settings → Automation → Daily summary email), not a client: see DailySummary. */
class DailySummaryMail extends Mailable
{
    public function __construct(public DailySummary $summary) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__('Daily summary :date', ['date' => $this->summary->until->format(AppServiceProvider::dateFormat())]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-summary', with: [
            'summary' => $this->summary,
            'invoiceUrl' => fn ($invoice) => InvoiceResource::getUrl('view', ['record' => $invoice]),
            'serviceUrl' => fn ($service) => $service ? ServiceResource::getUrl('edit', ['record' => $service]) : null,
            'describe' => $this->describeChanges(...),
        ]);
    }

    /** "expiry 01/01/2026 → 01/01/2027; nameservers ns1.a.gr, ns2.a.gr → ns1.b.gr, ns2.b.gr" */
    public function describeChanges(ActivityLog $log): string
    {
        $changes = $log->properties['changes'] ?? [];
        $date = fn (?string $date) => $date ? CarbonImmutable::parse($date)->format(AppServiceProvider::dateFormat()) : '–';
        $list = fn (array $nameservers) => implode(', ', $nameservers) ?: '–';

        return implode('; ', array_filter([
            isset($changes['expires_at']) ? __('expiry :old → :new', ['old' => $date($changes['expires_at'][0]), 'new' => $date($changes['expires_at'][1])]) : null,
            isset($changes['nameservers']) ? __('nameservers :old → :new', ['old' => $list($changes['nameservers'][0]), 'new' => $list($changes['nameservers'][1])]) : null,
        ]));
    }
}
