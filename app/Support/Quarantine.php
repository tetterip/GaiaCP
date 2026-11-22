<?php

namespace App\Support;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Reports\SalesOpportunities;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Quarantine": a service that has lapsed but can still be saved, worked out from its dates (not a stored status, so
 * billing, reminders and suspension are unchanged).
 *
 * - A domain: expired at the registrar (else on its due date) and still inside its TLD's grace days (30 when the TLD
 *   has none). Tracked (external) domains never are.
 * - A hosting account: suspended, overdue by no more than Settings → Automation → Suspend hosting after plus Hosting
 *   quarantine days (that many days after the automatic suspension). 0 = none is.
 *
 * Services → Hosting and Domains open on the active services plus these ("live"), the soonest due first; the ones past
 * quarantine show under All.
 */
class Quarantine
{
    public static function hostingDays(): int
    {
        return max(0, (int) config('gaiacp.automation.hosting_quarantine_days', 30));
    }

    /** The oldest due date a suspended hosting account in quarantine can have. */
    private static function hostingCutoff(): string
    {
        return today()->subDays(max(0, (int) config('gaiacp.automation.suspend_after_days')) + self::hostingDays())->toDateString();
    }

    public static function has(Service $service): bool
    {
        return match ($service->type) {
            ServiceType::Domain => in_array((int) $service->getKey(), self::domains()['quarantined'], true),
            ServiceType::Hosting => self::hostingDays() > 0
                && $service->status === ServiceStatus::Suspended
                && $service->next_due_date->toDateString() >= self::hostingCutoff(),
            default => false,
        };
    }

    /** Only the services in quarantine. */
    public static function scope(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => self::hosting($query))
            ->orWhereKey(self::domains()['quarantined']));
    }

    /** The active services, less the domains past quarantine, plus the ones in quarantine. */
    public static function live(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('status', ServiceStatus::Active)->whereKeyNot(self::domains()['lapsed']))
            ->orWhere(fn (Builder $query) => self::hosting($query))
            ->orWhereKey(self::domains()['quarantined']));
    }

    private static function hosting(Builder $query): Builder
    {
        if (self::hostingDays() === 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('type', ServiceType::Hosting)
            ->where('status', ServiceStatus::Suspended)
            ->whereOnDate('next_due_date', '>=', self::hostingCutoff());
    }

    /**
     * Active domains past their expiry, split into those still inside the grace days and those past them. Worked out
     * once per request (the grace days are per TLD, so in PHP).
     *
     * @return array{quarantined: list<int>, lapsed: list<int>}
     */
    private static function domains(): array
    {
        return once(function () {
            $today = today()->toImmutable();
            $split = ['quarantined' => [], 'lapsed' => []];

            Service::query()
                ->where('type', ServiceType::Domain)
                ->where('status', ServiceStatus::Active)
                ->whereDoesntHave('domainDetails', fn (Builder $q) => $q->where('registration_type', DomainRegistrationType::External))
                ->with('domainDetails')
                ->get()
                ->each(function (Service $service) use ($today, &$split) {
                    $expired = SalesOpportunities::expiry($service);

                    if ($expired === null || ! $expired->lessThan($today)) {
                        return;
                    }

                    $split[$today->greaterThan(SalesOpportunities::lastDay($service)) ? 'lapsed' : 'quarantined'][] = (int) $service->getKey();
                });

            return $split;
        });
    }
}
