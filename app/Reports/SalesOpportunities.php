<?php

namespace App\Reports;

use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Models\Tld;
use App\Providers\AppServiceProvider;
use App\Support\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports → Sales opportunities: services that point to something more to sell, from what is already recorded.
 *
 * - transfer: a domain we only track (registered elsewhere), or a hosting account whose domain has no domain service here
 * - hosting: a domain registered here with no hosting account for it here
 * - winback: a domain that expired and was not renewed, still within its TLD's grace days (before redemption)
 *
 * Each kind has its email template, sent from the page after the admin reads and edits it.
 */
final class SalesOpportunities
{
    public const KINDS = ['transfer', 'hosting', 'winback'];

    /** Grace days of a TLD that has none set. */
    public const DEFAULT_GRACE_DAYS = 30;

    public static function label(string $kind): string
    {
        return match ($kind) {
            'transfer' => __('Transfer to us'),
            'hosting' => __('Domains without hosting'),
            'winback' => __('Expired, not renewed'),
        };
    }

    public static function template(string $kind): MailTemplate
    {
        return match ($kind) {
            'transfer' => MailTemplate::DomainTransferOffer,
            'hosting' => MailTemplate::HostingOffer,
            'winback' => MailTemplate::DomainWinBack,
        };
    }

    /** @return list<int> ids of the services that are an opportunity of this kind */
    public static function ids(string $kind, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();

        return match ($kind) {
            'transfer' => self::transfer(),
            'hosting' => self::hosting(),
            'winback' => self::winback($today),
        };
    }

    /** @return list<int> */
    private static function transfer(): array
    {
        $tracked = self::domains()->where('status', ServiceStatus::Active)
            ->whereHas('domainDetails', fn (Builder $q) => $q->where('registration_type', DomainRegistrationType::External))
            ->pluck('id');

        // Every domain here (ours or tracked) counts: a tracked one is already listed by itself.
        $known = self::names(self::domains()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended]));

        $hosting = Service::query()->where('type', ServiceType::Hosting)->where('status', ServiceStatus::Active)
            ->whereNotNull('domain')->where('domain', '!=', '')
            ->get(['id', 'domain'])
            ->reject(fn (Service $s) => isset($known[self::name($s->domain)]))
            ->pluck('id');

        return $tracked->concat($hosting)->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<int> */
    private static function hosting(): array
    {
        $hosted = self::names(Service::query()->where('type', ServiceType::Hosting)
            ->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended]));

        return self::domains()->where('status', ServiceStatus::Active)
            ->whereDoesntHave('domainDetails', fn (Builder $q) => $q->where('registration_type', DomainRegistrationType::External))
            ->get(['id', 'domain'])
            ->reject(fn (Service $s) => isset($hosted[self::name($s->domain)]))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<int> */
    private static function winback(CarbonImmutable $today): array
    {
        $live = self::names(self::domains()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active]));

        return self::domains()
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Cancelled, ServiceStatus::Terminated])
            ->whereDate('next_due_date', '<=', $today)
            ->whereDoesntHave('domainDetails', fn (Builder $q) => $q->where('registration_type', DomainRegistrationType::External))
            ->with('domainDetails')
            ->get()
            // An expired one ordered again is a new live service: no longer an opportunity.
            ->reject(fn (Service $s) => $s->status !== ServiceStatus::Active && isset($live[self::name($s->domain)]))
            ->filter(function (Service $s) use ($today) {
                $expired = self::expiry($s);

                return $expired !== null && $expired->lessThan($today) && ! $today->greaterThan(self::lastDay($s));
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The day it expired at the registrar, else its due date. */
    public static function expiry(Service $service): ?CarbonImmutable
    {
        $date = $service->domainDetails?->expires_at ?? $service->next_due_date;

        return $date ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    /** The last day of its TLD's grace days, before redemption. */
    public static function lastDay(Service $service): CarbonImmutable
    {
        $grace = Tld::match($service->domain, activeOnly: false)?->grace_days ?? self::DEFAULT_GRACE_DAYS;

        return self::expiry($service)->addDays($grace);
    }

    /** What the row says about it. */
    public static function detail(string $kind, Service $service): string
    {
        $date = fn (CarbonInterface $d) => $d->format(AppServiceProvider::dateFormat());

        return match ($kind) {
            'transfer' => $service->type === ServiceType::Domain
                ? __('Registered elsewhere, tracked here')
                : __('Hosting here, domain registered elsewhere'),
            'hosting' => __('No hosting here'),
            'winback' => __('Expired on :date, recoverable until :last', ['date' => $date(self::expiry($service)), 'last' => $date(self::lastDay($service))]),
        };
    }

    /**
     * The template's own placeholders for this service, in the current language.
     *
     * @return array<string, string|null>
     */
    public static function values(string $kind, Service $service, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();
        $currency = $service->currency;
        $tld = Tld::match($service->domain, activeOnly: false);

        return ['domain' => $service->domain] + match ($kind) {
            'transfer' => ['transfer_price' => ($years = $tld?->terms('transfer')[0] ?? null) !== null
                ? Money::format((int) $tld->priceCents('transfer', $years, $currency), $currency)
                : null],
            'hosting' => [],
            'winback' => [
                'expiry_date' => self::expiry($service)?->format(AppServiceProvider::dateFormat()),
                'renewal_price' => Money::format($service->price_cents + (int) array_sum($tld?->lateFees(self::expiry($service), $today, $currency) ?? []), $currency),
            ],
        };
    }

    /** @return Builder<Service> */
    private static function domains(): Builder
    {
        return Service::query()->where('type', ServiceType::Domain);
    }

    /**
     * @param  Builder<Service>  $query
     * @return array<string, true> the normalized domain names of these services
     */
    private static function names(Builder $query): array
    {
        return $query->whereNotNull('domain')->pluck('domain')->mapWithKeys(fn ($d) => [self::name($d) => true])->all();
    }

    private static function name(?string $domain): string
    {
        return preg_replace('/^www\./', '', DomainName::normalize($domain));
    }
}
