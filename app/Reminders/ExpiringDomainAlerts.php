<?php

namespace App\Reminders;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Domains at a registrar here whose known expiry (from sync) is within DAYS and that are meant to renew: logged once
 * per expiry date as `domain.expiring`, which puts them in the admins' bell (App\Notifications\AdminAlerts). Part of
 * `gaiacp:send-reminders`; tracked (external) domains and ones set to end are left out.
 */
class ExpiringDomainAlerts
{
    public const DAYS = 7;

    /** @return int the domains logged (or that would be, on a dry run) */
    public function handle(CarbonImmutable $today, bool $dryRun = false): int
    {
        $services = Service::query()
            ->with('domainDetails')
            ->where('type', ServiceType::Domain)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->where('auto_terminate', false)
            ->whereHas('domainDetails', fn (Builder $query) => $query
                ->where('registration_type', '!=', DomainRegistrationType::External)
                ->whereDate('expires_at', '>=', $today)
                ->whereDate('expires_at', '<=', $today->addDays(self::DAYS)))
            ->get();

        $count = 0;
        foreach ($services as $service) {
            $expires = $service->domainDetails->expires_at->toDateString();
            if (ActivityLog::query()->where('event', 'domain.expiring')->where('service_id', $service->id)->where('properties->expires_at', $expires)->exists()) {
                continue;
            }

            $count++;
            if (! $dryRun) {
                ActivityLog::record('domain.expiring', "Domain {$service->domain} expires on {$expires}", $service, properties: ['expires_at' => $expires]);
            }
        }

        return $count;
    }
}
