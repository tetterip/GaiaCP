<?php

namespace App\Console\Commands;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:suspend-overdue {--date= : Run as if today were this date (Y-m-d)} {--dry-run : List what would be suspended}')]
#[Description('Suspend hosting accounts overdue by more than gaiacp.automation.suspend_after_days')]
class SuspendOverdue extends Command
{
    public function handle(ServiceLifecycle $lifecycle): int
    {
        $days = config('gaiacp.automation.suspend_after_days');

        if ($days === null) {
            $this->info('Auto-suspend is off (set "Suspend hosting after" in Settings).');

            return self::SUCCESS;
        }

        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        $services = Service::query()
            ->with(['client', 'server'])
            ->where('type', ServiceType::Hosting)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('server_id')
            ->whereDate('next_due_date', '<=', $today->subDays($days))
            // "Do not suspend until" on the service
            ->where(fn ($q) => $q->whereNull('override_suspend_until')->orWhereDate('override_suspend_until', '<', $today))
            ->get();

        $failed = 0;

        foreach ($services as $service) {
            $line = "{$service->client->name}: {$service->label} ({$service->domain}), due {$service->next_due_date->toDateString()}";

            if ($this->option('dry-run')) {
                $this->line("[dry run] would suspend {$line}");

                continue;
            }

            try {
                $lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
                $this->line("Suspended {$line}");
            } catch (ProvisioningException $e) {
                $failed++;
                $this->error("Failed {$line}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%d service(s) %s, %d failed.', $services->count() - $failed, $this->option('dry-run') ? 'to suspend' : 'suspended', $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
