<?php

namespace App\Console\Commands;

use App\Actions\DomainLifecycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:sync-domains')]
#[Description('Read the expiry date and nameservers of every active or suspended domain from its registrar (read-only there)')]
class SyncDomains extends Command
{
    public function handle(DomainLifecycle $domains): int
    {
        $services = Service::query()
            ->where('type', ServiceType::Domain)
            ->billable()
            ->whereNotNull('domain')
            // Registered elsewhere: no registrar account holds them.
            ->whereDoesntHave('domainDetails', fn ($q) => $q->where('registration_type', DomainRegistrationType::External))
            ->with('domainDetails')
            ->get();

        $failed = 0;

        // At a registrar that books nothing (Email Notifications) there is nothing to read.
        $services = $services->filter(fn (Service $service) => $domains->readsRegistrar($service));

        foreach ($services as $service) {
            try {
                $domains->sync($service);
                $this->line("{$service->domain}: expires {$service->domainDetails->expires_at?->toDateString()}");
            } catch (ProvisioningException $e) {
                $failed++;
                $this->warn("{$service->domain}: {$e->getMessage()}"); // already in the activity log
            }
        }

        $this->info("Synced {$services->count()} domain(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
