<?php

namespace App\Actions;

use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\Tld;
use App\Providers\AppServiceProvider;
use App\Support\MailTemplates;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A price change with notice: the service keeps its price until the first payment notice for a due date on or after
 * the chosen date, which bills the new one and makes it the service's price (apply(), from GenerateInvoices). Notices
 * already issued stay as they are. Each client gets one Price Change Notice listing their services. The new price is
 * the product's (or a domain's TLD renewal) price for the service's cycle and currency, the current one plus a
 * percentage (rounded to the cent), or an amount.
 */
class ChangePrice
{
    public const MODES = ['catalog', 'percent', 'amount'];

    public function __construct(private ClientMailer $mailer) {}

    /** Active or suspended, and not ending at the end of its cycle. */
    public static function changeable(Service $service): bool
    {
        return in_array($service->status, ServiceStatus::billable(), true) && ! $service->auto_terminate;
    }

    /** The earliest date a change can start: Settings → Automation → Price change notice. */
    public static function earliest(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays((int) config('gaiacp.automation.price_change_notice_days', 30));
    }

    /** The new price in the service's currency; null when it can't be priced that way (no product price for its cycle). */
    public static function newPrice(Service $service, string $mode, string|int|float|null $value = null): ?int
    {
        return match ($mode) {
            'catalog' => self::catalogPrice($service),
            'percent' => (int) round($service->price_cents * (100 + (float) $value) / 100),
            'amount' => Money::toCents((string) $value),
        };
    }

    /** The product's price for its cycle, or a domain's TLD renewal price for its term (not for a domain only tracked here). */
    public static function catalogPrice(Service $service): ?int
    {
        if ($service->type === ServiceType::Domain) {
            if ($service->domainDetails?->registration_type === DomainRegistrationType::External) {
                return null;
            }

            return Tld::match($service->domain, activeOnly: false)?->priceCents('renew', $service->registrationYears(), $service->currency);
        }

        return $service->product?->priceFor($service->billing_cycle, $service->currency);
    }

    /**
     * @param  iterable<Service>  $services
     * @return array{scheduled: int, skipped: int, emailed: int}
     */
    public function schedule(iterable $services, string $mode, string|int|float|null $value, CarbonImmutable $from, bool $email = true): array
    {
        $summary = ['scheduled' => 0, 'skipped' => 0, 'emailed' => 0];
        $byClient = [];

        foreach ($services as $service) {
            $new = self::changeable($service) ? self::newPrice($service, $mode, $value) : null;

            if ($new === null || $new < 0 || $new === $service->price_cents) {
                $summary['skipped']++;

                continue;
            }

            $service->update(['new_price_cents' => $new, 'new_price_from' => $from]);
            ActivityLog::record('service.price_change_scheduled', sprintf(
                'Price change from %s to %s for renewals from %s',
                Money::format($service->price_cents, $service->currency),
                Money::format($new, $service->currency),
                $from->toDateString(),
            ), $service, properties: ['from_cents' => $service->price_cents, 'to_cents' => $new, 'from' => $from->toDateString()]);

            $summary['scheduled']++;
            $byClient[$service->client_id][] = $service;
        }

        if ($email) {
            foreach ($byClient as $changed) {
                $summary['emailed'] += $this->notify($changed) ? 1 : 0;
            }
        }

        return $summary;
    }

    public function cancel(Service $service): void
    {
        if ($service->new_price_from === null) {
            return;
        }

        ActivityLog::record('service.price_change_cancelled', sprintf('Price change to %s from %s cancelled',
            Money::format((int) $service->new_price_cents, $service->currency), $service->new_price_from->toDateString()), $service);
        $service->update(['new_price_cents' => null, 'new_price_from' => null]);
    }

    /** Makes a scheduled price the service's own once a notice bills it (its due date is on or after the change). */
    public static function apply(Service $service): void
    {
        if ($service->new_price_from === null || $service->next_due_date->lt($service->new_price_from)) {
            return;
        }

        ActivityLog::record('service.price_changed', sprintf('Price changed from %s to %s',
            Money::format($service->price_cents, $service->currency), Money::format((int) $service->new_price_cents, $service->currency)), $service);
        $service->update(['price_cents' => $service->new_price_cents, 'new_price_cents' => null, 'new_price_from' => null]);
    }

    /** @param  list<Service>  $services  one client's */
    private function notify(array $services): bool
    {
        $first = $services[0];
        $from = $first->new_price_from;

        // Sent although its template switch is off: the admin ticked "Email the clients".
        return $this->mailer->send(MailTemplate::PriceChange, $first->client, fn () => [
            'effective_date' => $from->format(AppServiceProvider::dateFormat()),
            'price_changes' => MailTemplates::table(
                [__('Service'), __('Billing cycle'), __('Current price'), __('New price')],
                collect($services)->map(fn (Service $s) => [
                    trim($s->label.($s->domain && $s->domain !== $s->label ? ' '.$s->domain : '')),
                    $s->billing_cycle->getLabel(),
                    Money::format($s->price_cents, $s->currency),
                    Money::format((int) $s->new_price_cents, $s->currency),
                ]),
            ),
        ], $first, evenIfOff: true);
    }
}
