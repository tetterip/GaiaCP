<?php

namespace App\Invoices;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\InvoiceItem;
use App\Models\Tld;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Late renewal fees (Settings → Domains → Charge grace and redemption fees): an unpaid payment notice
 * for a domain that has already expired gets the TLD's grace fee, and its redemption fee as well once
 * the grace days are over (Settings → Domain pricing). Each fee is a line of its own on the notice, with the
 * notice's tax rate; it is paid with the domain's line. Safe to re-run: a fee already on the notice is
 * not added again.
 */
class AddDomainLateFees
{
    /** @return int the fee lines added (that would be added, on a dry run) */
    public function handle(CarbonInterface $today, bool $dryRun = false): int
    {
        if (! config('gaiacp.domains.late_fees', false)) {
            return 0;
        }

        $today = CarbonImmutable::parse($today)->startOfDay();
        $added = 0;

        $lines = InvoiceItem::query()
            ->whereNull('fee')
            ->whereNull('payment_id')
            ->whereHas('invoice', fn (Builder $q) => $q->unpaid())
            ->whereHas('service', fn (Builder $q) => $q
                ->where('type', ServiceType::Domain)
                ->whereHas('domainDetails', fn (Builder $q) => $q
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<', $today)
                    ->where('registration_type', '!=', DomainRegistrationType::External)))
            ->with(['invoice', 'service.domainDetails'])
            ->get();

        foreach ($lines as $line) {
            $service = $line->service;
            $tld = Tld::match($service->domain, activeOnly: false);

            foreach ($tld?->lateFees($service->domainDetails->expires_at, $today, $line->invoice->currency) ?? [] as $fee => $cents) {
                $exists = $line->invoice->items()->where('service_id', $service->id)->whereDate('due_date', $line->due_date)->where('fee', $fee)->exists();

                if ($exists) {
                    continue;
                }

                $added++;

                if (! $dryRun) {
                    DB::transaction(fn () => $this->add($line, $fee, $cents));
                }
            }
        }

        return $added;
    }

    private function add(InvoiceItem $line, string $fee, int $cents): void
    {
        $invoice = $line->invoice;
        $tax = Tax::apply($cents, $invoice->tax_rate, $invoice->tax_inclusive);
        $label = $fee === 'grace' ? 'Grace period fee' : 'Redemption period fee';

        $invoice->items()->create([
            'service_id' => $line->service_id,
            'fee' => $fee,
            'description' => "{$label}: {$line->service->domain}",
            'due_date' => $line->due_date,
            'period_end' => $line->period_end,
            'amount_cents' => $cents,
            'tax_cents' => $tax['tax'],
            'total_cents' => $tax['total'],
        ]);

        $invoice->update([
            'subtotal_cents' => $invoice->subtotal_cents + $tax['total'] - $tax['tax'],
            'tax_cents' => $invoice->tax_cents + $tax['tax'],
            'total_cents' => $invoice->total_cents + $tax['total'],
        ]);

        ActivityLog::record('invoice.fee_added', sprintf(
            '%s of %s added to payment notice %s',
            $label,
            Money::format($tax['total'], $invoice->currency),
            $invoice->number,
        ), $line->service, properties: ['invoice_id' => $invoice->id, 'fee' => $fee]);
    }
}
