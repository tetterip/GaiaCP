<?php

namespace Plugins\Blacklists;

use App\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

/** Every active server's IP looked up in DNS blacklists (DNSBL) every hour; a new listing and its removal go to the bell. */
class BlacklistsPlugin extends Plugin
{
    public function id(): string
    {
        return 'blacklists';
    }

    public function name(): string
    {
        return 'IP blacklist check';
    }

    public function description(): string
    {
        return __('Checks your servers\' IP addresses against spam blacklists every hour and tells you in the bell when one is listed, before clients find their emails in spam.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return BlacklistsSettings::class;
    }

    public function pages(): array
    {
        return [BlacklistsSettings::class];
    }

    public function commands(): array
    {
        return [CheckBlacklists::class];
    }

    public function settingKeys(): array
    {
        return ['blacklists.zones'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('gaiacp:check-blacklists')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
    }
}
