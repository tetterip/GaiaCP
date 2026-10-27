<?php

namespace Plugins\Webhooks;

use App\Events\GaiaEvent;
use App\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

/** GaiaCP's events sent as signed JSON POSTs to other systems (Settings → Webhooks). */
class WebhooksPlugin extends Plugin
{
    public function id(): string
    {
        return 'webhooks';
    }

    public function name(): string
    {
        return 'Webhooks';
    }

    public function description(): string
    {
        return __('Sends GaiaCP\'s events (orders, payments, services, tickets, servers) as signed JSON to the URLs you choose, for your own scripts, Zapier, Make or n8n.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return WebhooksPage::class;
    }

    public function pages(): array
    {
        return [WebhooksPage::class, WebhookDeliveriesPage::class];
    }

    public function commands(): array
    {
        return [SendWebhooks::class];
    }

    public function moduleLogs(): array
    {
        return ['webhooks' => 'Webhooks'];
    }

    public function events(): array
    {
        return ['*'];
    }

    /** Only queued here; gaiacp:send-webhooks sends them, so no action waits for a receiver. */
    public function handleEvent(GaiaEvent $event): void
    {
        app(WebhookSender::class)->queue($event);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('gaiacp:send-webhooks')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();
    }
}
