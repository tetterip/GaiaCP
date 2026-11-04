<?php

namespace App\Console\Commands;

use App\Actions\ModuleQueue;
use App\Models\ModuleQueueItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:retry-module-queue {--dry-run : List what would be retried without calling any server}')]
#[Description('Retry the failed panel and registrar actions of Services → Module queue that retry by themselves (Settings → Automation → Retry failed actions)')]
class RetryModuleQueue extends Command
{
    public function handle(ModuleQueue $queue): int
    {
        $limit = max(0, (int) config('gaiacp.automation.module_retries'));

        if ($this->option('dry-run')) {
            foreach ($limit > 0 ? $queue->due($limit) : [] as $item) {
                $this->line(sprintf('[dry run] would retry %s of %s (%d automatic tries so far): %s', $item->action, $item->service?->domain ?: $item->service?->label, $item->auto_attempts, $item->error));
            }

            $this->info(sprintf('%d open item(s)%s.', ModuleQueueItem::query()->open()->count(), $limit > 0 ? '' : '; automatic retries are off'));

            return self::SUCCESS;
        }

        $result = $queue->runDue($limit);

        $this->info(sprintf('%d no longer needed, %d retried, %d done, %d gave up.', $result['closed'], $result['retried'], $result['fixed'], $result['gave_up']));

        return self::SUCCESS;
    }
}
