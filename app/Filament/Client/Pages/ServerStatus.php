<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Monitoring\MonitorStatus;
use App\Support\ServerIncidents;
use App\Support\StatusPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The servers' status page (/status, Settings → Public area → Status page), for visitors and clients: whether every
 * server works, the ones that don't first, and each server's uptime squares (App\Support\StatusPage).
 */
class ServerStatus extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $title = 'Server status';

    protected static ?string $slug = 'status';

    protected static ?int $navigationSort = 85;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected string $view = 'filament.client.server-status';

    public static function canAccess(): bool
    {
        return StatusPage::enabled();
    }

    /** @return Collection<int, array<string, mixed>> the down ones first, then those in maintenance, then the rest by name */
    public function servers(): Collection
    {
        return StatusPage::servers()
            ->sortBy(fn (array $server) => match ($server['status']) {
                MonitorStatus::Down => 0,
                MonitorStatus::Maintenance => 1,
                default => 2,
            })
            ->values();
    }

    public function message(): string
    {
        return ServerIncidents::message();
    }
}
