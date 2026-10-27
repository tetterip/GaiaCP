<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Monitoring\ServerMonitoring;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListServers extends ListRecords
{
    protected static string $resource = ServerResource::class;

    /** The uptime bar's range: 24h or 30d (App\Monitoring\UptimeHistory::RANGES). */
    #[Url(as: 'uptime')]
    public string $uptimeRange = '24h';

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('uptime24h')
                    ->label(__('24 hours'))
                    ->action(fn () => $this->uptimeRange = '24h'),
                Action::make('uptime30d')
                    ->label(__('30 days'))
                    ->action(fn () => $this->uptimeRange = '30d'),
            ])
                ->label(fn () => $this->uptimeRange === '30d' ? __('Uptime: 30 days') : __('Uptime: 24 hours'))
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->button()
                ->visible(fn () => ServersTable::monitored()),
            // Reads the monitors now instead of waiting for the next scheduled check (every 5 minutes).
            Action::make('checkMonitors')
                ->label('Check status now')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->visible(fn () => ServersTable::monitored())
                ->action(function (ServerMonitoring $monitoring) {
                    $result = $monitoring->check();

                    $result['failed'] === []
                        ? Notification::make()->success()->title(__('Checked :count server(s)', ['count' => $result['servers']]))->send()
                        : Notification::make()->danger()->title(__('Could not read the monitoring service'))->body(implode("\n", $result['failed']))->persistent()->send();
                }),
            CreateAction::make(),
        ];
    }
}
