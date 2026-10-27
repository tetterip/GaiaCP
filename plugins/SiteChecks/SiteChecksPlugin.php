<?php

namespace Plugins\SiteChecks;

use App\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

/** Every active hosting account's domain checked daily: does it point to its server, is its HTTPS certificate fine. */
class SiteChecksPlugin extends Plugin
{
    public function id(): string
    {
        return 'site-checks';
    }

    public function name(): string
    {
        return 'Site checks';
    }

    public function description(): string
    {
        return __('Checks every day that each active hosting account\'s domain still points to its server and that its HTTPS certificate is valid and not about to expire, and tells you in the bell.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return SiteChecksSettings::class;
    }

    public function pages(): array
    {
        return [SiteChecksSettings::class, SiteChecksPage::class];
    }

    public function commands(): array
    {
        return [CheckSites::class];
    }

    public function settingKeys(): array
    {
        return ['site_checks.ssl_warn_days'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('gaiacp:check-sites')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
