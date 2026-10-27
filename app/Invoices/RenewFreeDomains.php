<?php

namespace App\Invoices;

use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Free domains (price 0) get no payment notice, so the daily run renews them itself when a paid one
 * would get its notice: a payment of 0 through RecordPayment, which moves the due date and renews at
 * the registrar. With Settings → Domains → "Free domains renew only with active hosting" on, a free
 * domain whose client has no active or suspended hosting for it (same domain or same order) is set
 * to end at its due date instead. Safe to re-run: the due date has moved on, or auto renew is off.
 */
class RenewFreeDomains
{
    public function __construct(private RecordPayment $payments) {}

    /** @return array{renewed: int, ended: int} */
    public function handle(CarbonInterface $today, bool $dryRun = false): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $result = ['renewed' => 0, 'ended' => 0];

        foreach ($this->due($today) as $service) {
            if (config('gaiacp.domains.free_renew_requires_product', true) && ! $this->hasHosting($service)) {
                $result['ended']++;

                if (! $dryRun) {
                    $service->update(['auto_terminate' => true, 'auto_terminate_reason' => 'Free domain without active hosting']);
                    ActivityLog::record('domain.not_renewed', "Free domain {$service->domain} not renewed: the client has no active hosting for it. It ends on {$service->next_due_date->toDateString()}.", $service);
                }

                continue;
            }

            $result['renewed']++;

            if (! $dryRun) {
                $this->payments->handle($service, 0, $today, PaymentMethod::Other->value, notes: 'Free domain renewal');
            }
        }

        return $result;
    }

    /** @return Collection<int, Service> */
    public function due(CarbonImmutable $today): Collection
    {
        return Service::query()
            ->billable()
            ->where('type', ServiceType::Domain)
            ->where('price_cents', 0)
            // A first payment still owed is billed by GenerateInvoices instead.
            ->where(fn (Builder $q) => $q->whereNull('first_payment_cents')->orWhere('first_payment_cents', 0)->orWhereHas('payments'))
            ->where('auto_terminate', false)
            ->whereDate('next_due_date', '<=', $today->addDays((int) config('gaiacp.invoices.days_before', 14)))
            ->with('domainDetails')
            ->orderBy('next_due_date')
            ->get();
    }

    private function hasHosting(Service $service): bool
    {
        return Service::query()
            ->where('client_id', $service->client_id)
            ->where('type', ServiceType::Hosting)
            ->whereIn('status', ServiceStatus::billable())
            ->where(fn (Builder $q) => $q->where('domain', $service->domain)
                ->when($service->order_id, fn (Builder $q) => $q->orWhere('order_id', $service->order_id)))
            ->exists();
    }
}
