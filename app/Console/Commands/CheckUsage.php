<?php

namespace App\Console\Commands;

use App\Actions\CheckUsage as CheckUsageAction;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:check-usage {--dry-run : List the accounts that would be warned without emailing anyone}')]
#[Description('Read every active hosting account\'s disk space and bandwidth, and warn the clients near their package\'s limit (Settings → Automation → Usage warning)')]
class CheckUsage extends Command
{
    public function handle(CheckUsageAction $check): int
    {
        if (CheckUsageAction::threshold() === null) {
            $this->info('The usage warning is off (Settings → Automation).');

            return self::SUCCESS;
        }

        $result = $check->handle(CarbonImmutable::today(), (bool) $this->option('dry-run'), function (Service $service, array $new) {
            $this->line(sprintf('%s: %s', $service->domain ?: $service->label, collect($new)->map(fn (int $percent, string $kind) => "{$kind} {$percent}%")->join(', ')));
        });

        $this->info(sprintf('%d read, %d %s, %d not read', $result['checked'], $result['warned'], $this->option('dry-run') ? 'to warn' : 'warned', $result['failed']));

        return self::SUCCESS;
    }
}
