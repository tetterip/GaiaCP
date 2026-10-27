<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Filament\Actions\TestConnectionAction;
use App\Filament\Resources\Servers\Pages\CompareServer;
use App\Models\Server;
use App\Monitoring\MonitorStatus;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withAccountsCount())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state) => app(PluginManager::class)->serverModule($state)?->label() ?? $state)
                    ->badge(),
                TextColumn::make('hostname')
                    ->formatStateUsing(fn ($record) => "{$record->hostname}:{$record->port}")
                    ->description(fn (Server $record) => $record->ip_address)
                    ->searchable(['hostname', 'ip_address']),
                // Active and suspended hosting accounts, against the maximum when it is set.
                ViewColumn::make('accounts_count')
                    ->label('Accounts')
                    ->view('filament.servers.accounts')
                    ->sortable(),
                TextColumn::make('monthly_cost_cents')
                    ->label('Monthly cost')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Money::format($state, config('gaiacp.currency')))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // From the monitor it is linked to (App\Monitoring\ServerMonitoring); shown once a server is.
                TextColumn::make('monitor_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (MonitorStatus $state) => $state->label())
                    ->color(fn (MonitorStatus $state) => $state->color())
                    ->placeholder(fn (Server $record) => $record->monitor_module ? __('Not checked yet') : null)
                    ->tooltip(fn (Server $record) => self::monitorTooltip($record))
                    ->visible(fn () => self::monitored()),
                // A dot per half hour or day (Servers → Uptime: 24 hours / 30 days), App\Monitoring\UptimeHistory.
                ViewColumn::make('uptime')
                    ->label(fn ($livewire) => ($livewire->uptimeRange ?? '24h') === '30d' ? __('Uptime, 30 days') : __('Uptime, 24 hours'))
                    ->view('filament.servers.uptime-column')
                    ->visible(fn () => self::monitored()),
                IconColumn::make('active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->poll(fn () => self::monitored() ? '60s' : null)
            ->recordActions([
                Action::make('panelLogin')
                    ->label('Log in')
                    ->tooltip(__('Log in to control panel'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Server $record) => app(PluginManager::class)->serverModules()->has($record->type))
                    ->url(fn (Server $record) => route('filament.admin.server-panel-login', ['server' => $record->id]), shouldOpenInNewTab: true),
                ActionGroup::make([
                    Action::make('statusPage')
                        ->label('Status page')
                        ->icon(Heroicon::OutlinedSignal)
                        ->color('gray')
                        ->visible(fn (Server $record) => filled($record->status_url))
                        ->url(fn (Server $record) => $record->status_url, shouldOpenInNewTab: true),
                    TestConnectionAction::make(),
                    CompareServer::action(),
                    EditAction::make(),
                ]),
            ]);
    }

    /** Whether a monitoring plugin is on and a server is linked to it. */
    public static function monitored(): bool
    {
        return app(PluginManager::class)->monitoringModules()->isNotEmpty() && Server::query()->whereNotNull('monitor_module')->exists();
    }

    /** "Uptime Kuma · since 17/10/2026 22:05 · checked 22:10" */
    private static function monitorTooltip(Server $record): ?string
    {
        if (! $record->monitor_module) {
            return null;
        }

        $parts = [app(PluginManager::class)->monitoringModule($record->monitor_module)?->monitoringLabel() ?? $record->monitor_module];
        if ($record->monitor_status_since) {
            $parts[] = __('since :time', ['time' => $record->monitor_status_since->format(AppServiceProvider::dateFormat().' H:i')]);
        }
        if ($record->monitor_checked_at) {
            $parts[] = __('checked :time', ['time' => $record->monitor_checked_at->format('H:i')]);
        }

        return implode(' · ', $parts);
    }
}
