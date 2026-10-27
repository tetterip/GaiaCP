<?php

namespace Plugins\Webhooks;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

/**
 * gaiacp:send-webhooks: sends the webhook deliveries that are due (new events and retries), every minute while the
 * plugin is on, and deletes deliveries older than Settings → Logging keeps module log entries.
 */
class SendWebhooks extends Command
{
    protected $signature = 'gaiacp:send-webhooks';

    protected $description = 'Send the webhook deliveries that are due (Settings → Webhooks)';

    public function handle(PluginManager $plugins, WebhookSender $sender): int
    {
        if (! $plugins->enabled('webhooks')) {
            $this->info('The Webhooks plugin is off.');

            return self::SUCCESS;
        }

        $summary = $sender->sendDue();

        $pruned = WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('gaiacp.debug.module_log_days')))->startOfDay())
            ->whereIn('status', [DeliveryStatus::Sent, DeliveryStatus::Failed])
            ->delete();

        $this->info("{$summary['sent']} sent, {$summary['failed']} failed".($pruned ? ", {$pruned} old deliveries deleted" : '').'.');

        return self::SUCCESS;
    }
}
