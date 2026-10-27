<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Monitoring\ServerMonitoring;
use App\Plugins\PluginManager;
use App\Provisioning\ServerModule;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ServerForm
{
    /** The module of the type picked on the form (the plugin that provides it, see Settings → Plugins). */
    private static function type(Get $get): ?ServerModule
    {
        return app(PluginManager::class)->serverModule($get('type'));
    }

    /** @return array<string, string> types of enabled plugins (Settings → Plugins), plus the one this server already has */
    private static function typeOptions(?Server $record): array
    {
        $plugins = app(PluginManager::class);
        $modules = $plugins->serverModules();

        if ($record && ($current = $plugins->serverModule($record->type))) {
            $modules->put($current->key(), $current);
        }

        return $modules->map(fn (ServerModule $module) => $module->label())->all();
    }

    /** @return array<string, string> monitoring services of enabled plugins, plus the one this server already uses */
    private static function monitoringOptions(?Server $record): array
    {
        $plugins = app(PluginManager::class);
        $modules = $plugins->monitoringModules();

        if ($record && ($current = $plugins->monitoringModule($record->monitor_module))) {
            $modules->put($current->monitoringKey(), $current);
        }

        return $modules->map(fn (MonitoringModule $module) => $module->monitoringLabel())->all();
    }

    /** @return array{0: array<string, string>, 1: ?string} the service's monitors (ref => name) and why they could not be read */
    private static function monitors(?string $key): array
    {
        $module = app(PluginManager::class)->monitoringModule($key);
        if (! $module) {
            return [[], null];
        }

        // Once a request: the options and the helper text both ask, and a failure is not cached.
        return once(function () use ($module) {
            try {
                $monitors = app(ServerMonitoring::class)->monitors($module);
            } catch (MonitoringException $e) {
                return [[], $e->getMessage()];
            }

            return self::monitorOptions($monitors);
        });
    }

    /** @return array{0: array<string, string>, 1: null} */
    private static function monitorOptions(array $monitors): array
    {
        return [collect($monitors)->mapWithKeys(fn ($monitor) => [$monitor->ref => $monitor->host ? "{$monitor->name} · {$monitor->host}" : $monitor->name])->all(), null];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(fn (?Server $record) => self::typeOptions($record))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                if ($type = self::type($get)) {
                                    $set('port', $type->defaultPort());
                                }
                            }),
                        TextInput::make('hostname')
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('e.g. server1.example.com')),
                        TextInput::make('port')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('username')
                            ->required(fn (Get $get) => self::type($get)?->usernameRequired() ?? true)
                            ->maxLength(255)
                            ->autocomplete('off')
                            ->helperText(fn (Get $get) => self::type($get)?->usernameHelp()),
                        TextInput::make('api_token')
                            ->label('API token / password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            // Never send the stored secret back to the browser; a blank field keeps it.
                            ->formatStateUsing(fn () => null)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation, Get $get) => ($operation === 'edit' ? __('Leave blank to keep the current one.').' ' : '').self::type($get)?->tokenHelp()),
                        // Each type's own fields (e.g. Plesk's IP for new subscriptions), shown only for that type.
                        ...app(PluginManager::class)->all()->flatMap(fn ($plugin) => $plugin->serverModules())
                            ->map(fn (ServerModule $module) => Group::make($module->serverFields())
                                ->columns(2)
                                ->columnSpanFull()
                                ->visible(fn (Get $get) => $get('type') === $module->key()))
                            ->values()->all(),
                        Toggle::make('verify_tls')
                            ->label('Verify TLS certificate')
                            ->default(true)
                            ->helperText(__('Turn off only for a server still on a self-signed certificate.')),
                        Toggle::make('active')
                            ->default(true)
                            ->helperText(__('Inactive servers are not offered for new services.')),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Capacity and costs'))
                    ->description(__('All optional. The Servers list shows accounts against the maximum; reports show profit after the monthly cost.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('max_accounts')
                            ->label('Maximum accounts')
                            ->integer()
                            ->minValue(1)
                            ->helperText(__('Empty = no limit.')),
                        TextInput::make('monthly_cost_cents')
                            ->label('Monthly cost')
                            ->prefix(fn () => Currencies::base())
                            ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
                            ->formatStateUsing(fn ($state) => $state === null ? null : Money::toDecimal((int) $state))
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null)
                            ->helperText(__('What the server costs you a month.')),
                        TextInput::make('ip_address')
                            ->label('IP address')
                            ->ip()
                            ->maxLength(45),
                        TextInput::make('status_url')
                            ->label('Status page URL')
                            ->url()
                            ->maxLength(255)
                            ->helperText(__('Opened from the Servers list.')),
                    ]),
                Section::make(__('Monitoring'))
                    ->description(__('Link the server to its monitor in a monitoring service (a plugin) to see whether it is up on the Servers list and the dashboard. Super admins are told in the bell when it goes down.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (?Server $record) => self::monitoringOptions($record) !== [])
                    ->schema([
                        Select::make('monitor_module')
                            ->label('Monitoring service')
                            ->options(fn (?Server $record) => self::monitoringOptions($record))
                            ->placeholder(__('Not monitored'))
                            ->live()
                            // Pre-selects the monitor that checks this server's hostname or IP address.
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                $module = app(PluginManager::class)->monitoringModule($state);
                                $set('monitor_ref', $module ? app(ServerMonitoring::class)->suggest($module, $get('hostname'), $get('ip_address')) : null);
                            }),
                        Select::make('monitor_ref')
                            ->label('Monitor')
                            ->searchable()
                            ->options(function (Get $get) {
                                $options = self::monitors($get('monitor_module'))[0];
                                // A monitor that is paused or gone keeps its place, by its stored name.
                                $current = $get('monitor_ref');

                                return filled($current) && ! isset($options[$current]) ? [$current => $current] + $options : $options;
                            })
                            ->visible(fn (Get $get) => filled($get('monitor_module')))
                            ->required(fn (Get $get) => filled($get('monitor_module')))
                            ->helperText(fn (Get $get) => self::monitors($get('monitor_module'))[1]),
                        // The public status page (Settings → Public area): the name only, never the hostname or IP address
                        Toggle::make('on_status_page')
                            ->label('Show on the status page')
                            ->default(true)
                            ->live()
                            ->visible(fn (Get $get) => filled($get('monitor_module')))
                            ->helperText(__('Settings → Public area → Server status page lists the monitored servers with their state and uptime, never their hostname or IP address.')),
                        TextInput::make('public_name')
                            ->label('Public name')
                            ->maxLength(100)
                            ->placeholder(fn (Get $get) => $get('name'))
                            ->visible(fn (Get $get) => filled($get('monitor_module')) && $get('on_status_page'))
                            ->helperText(__('Shown instead of the name on the status page, e.g. "Web server 1". Empty: the name.')),
                    ]),
            ]);
    }
}
