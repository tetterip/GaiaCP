<?php

namespace App\Console\Commands;

use App\Actions\CertificateLifecycle;
use App\Enums\CertificateStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Certificate;
use App\Provisioning\ProvisioningException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:check-certificates {--dry-run : Only list what would be done}')]
#[Description('Read SSL certificates waiting to be issued from their provider; reissue or renew the ones about to end')]
class CheckCertificates extends Command
{
    public function handle(CertificateLifecycle $certificates): int
    {
        $soon = today()->addDays(CertificateLifecycle::DAYS_BEFORE);
        $failed = 0;

        $rows = Certificate::query()
            ->whereNotNull('remote_id')
            ->whereIn('status', [CertificateStatus::Pending, CertificateStatus::Issued])
            ->whereHas('service', fn ($query) => $query->where('type', ServiceType::Ssl))
            ->with('service.client')
            ->get();

        foreach ($rows as $certificate) {
            $service = $certificate->service;
            $step = match (true) {
                $certificate->status === CertificateStatus::Pending => 'refresh',
                $service->status !== ServiceStatus::Active || $certificate->expires_at === null => null,
                $certificate->expires_at->isPast() => 'refresh',
                // The order goes on after this certificate ends (a certificate lasts at most 200 days): a new one in it.
                $certificate->expires_at->lte($soon) && $certificate->ends_at?->gt($certificate->expires_at->addDays(CertificateLifecycle::DAYS_BEFORE)) => 'reissue',
                // The order itself ends soon and the service is paid beyond it: the next period's order.
                $certificate->ends_at !== null && $certificate->ends_at->lte($soon) && $service->next_due_date->gt($certificate->ends_at) => 'renew',
                default => null,
            };

            if ($step === null) {
                continue;
            }

            $this->line("{$certificate->commonName()}: {$step}");

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                match ($step) {
                    'refresh' => $certificates->refresh($service),
                    'reissue' => $certificates->reissue($service),
                    'renew' => $certificates->renew($service),
                };
            } catch (ProvisioningException $e) {
                $failed++;
                $this->warn("{$certificate->commonName()}: {$e->getMessage()}"); // already in the activity log
            }
        }

        $this->info("Checked {$rows->count()} certificate(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
