<?php

namespace App\Console\Commands;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:terminate-scheduled {--date= : Run as if today were this date (Y-m-d)} {--dry-run : List what would be terminated}')]
#[Description('Terminate services marked "Auto-terminate at the end of the cycle" once their paid period has ended')]
class TerminateScheduled extends Command
{
    public function handle(ServiceLifecycle $lifecycle): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        $services = Service::query()
            ->with(['client', 'server'])
            ->where('auto_terminate', true)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereDate('next_due_date', '<=', $today)
            ->get();

        $failed = 0;

        foreach ($services as $service) {
            $line = "{$service->client->name}: {$service->label} ({$service->domain}), paid until {$service->next_due_date->toDateString()}";

            if ($this->option('dry-run')) {
                $this->line("[dry run] would terminate {$line}");

                continue;
            }

            try {
                $lifecycle->terminate($service);
                ActivityLog::record('service.auto_terminated', 'Terminated at the end of its cycle'.($service->auto_terminate_reason ? ": {$service->auto_terminate_reason}" : ''), $service);
                $this->line("Terminated {$line}");
            } catch (ProvisioningException $e) {
                $failed++;
                $this->error("Failed {$line}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%d service(s) %s, %d failed.', $services->count() - $failed, $this->option('dry-run') ? 'to terminate' : 'terminated', $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
