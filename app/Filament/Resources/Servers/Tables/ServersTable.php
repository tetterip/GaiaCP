<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Filament\Actions\TestConnectionAction;
use App\Filament\Resources\Servers\Pages\CompareServer;
use App\Models\Server;
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
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withAccountsCount())
            ->columns([
                // The monitor's status sits under the name and the panel type under the hostname, so the list stays narrow.
                TextColumn::make('name')
                    ->description(fn (Server $record) => self::statusBadge($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('hostname')
                    ->formatStateUsing(fn ($record) => "{$record->hostname}:{$record->port}")
                    ->description(fn (Server $record) => view('filament.servers.badge', [
                        'label' => app(PluginManager::class)->serverModule($record->type)?->label() ?? $record->type,
                        'color' => 'primary',
                        'after' => $record->ip_address,
                    ]))
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

    /** The monitor's status (App\Monitoring\ServerMonitoring), once a server is linked to one. */
    private static function statusBadge(Server $record): ?View
    {
        if (! $record->monitor_module || app(PluginManager::class)->monitoringModules()->isEmpty()) {
            return null;
        }

        return view('filament.servers.badge', [
            'label' => $record->monitor_status?->label() ?? __('Not checked yet'),
            'color' => $record->monitor_status?->color() ?? 'gray',
            'tooltip' => self::monitorTooltip($record),
        ]);
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
