<?php

namespace App\Filament\Resources\Registrars\Schemas;

use App\Models\Registrar;
use App\Plugins\PluginManager;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RegistrarForm
{
    /** @return array<string, string> modules of enabled plugins (Settings → Plugins), plus the one this account already uses */
    private static function moduleOptions(?Registrar $record): array
    {
        $plugins = app(PluginManager::class);
        $modules = $plugins->registrarModules();

        if ($record && ($current = $plugins->registrarModule($record->module))) {
            $modules->put($current->key(), $current);
        }

        return $modules->map(fn (RegistrarModule $module) => $module->label())->all();
    }

    private static function module(Get $get): ?RegistrarModule
    {
        return filled($get('module')) ? app(PluginManager::class)->registrarModule((string) $get('module')) : null;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->default('Easy.gr'),
                        Select::make('module')
                            ->options(fn (?Registrar $record) => self::moduleOptions($record))
                            ->default(fn () => app(PluginManager::class)->registrarModules()->keys()->first())
                            ->live()
                            ->required(),
                        // Each module's own login fields (credentials.*), shown only for that module.
                        ...app(PluginManager::class)->all()->flatMap(fn ($plugin) => $plugin->registrarModules())
                            ->map(fn (RegistrarModule $module) => Group::make($module->credentialFields())
                                ->columns(2)
                                ->columnSpanFull()
                                ->visible(fn (Get $get) => $get('module') === $module->key()))
                            ->values()->all(),
                        Toggle::make('verify_tls')
                            ->label('Verify TLS certificate')
                            ->visible(fn (Get $get) => self::module($get)?->credentialFields() !== [])
                            ->default(true)
                            ->helperText(__('Turn off only while the registrar\'s API certificate is broken (e.g. expired), and back on once it is fixed: the login is sent on every call.')),
                        Toggle::make('active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default registrar')
                            ->default(fn () => Registrar::query()->get()->every(fn (Registrar $registrar) => $registrar->isManual()))
                            ->helperText(__('Pre-selected as the preferred registrar of a new TLD under Domain pricing.')),
                    ]),

            ]);
    }
}
