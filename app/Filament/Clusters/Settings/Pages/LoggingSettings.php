<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Pages\ErrorLog;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Filament\Resources\ModuleLogs\ModuleLogResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Debugging options: the module log switch and how long the module, email and activity logs and the bell notifications are kept. */
class LoggingSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Logging';

    protected static ?string $slug = 'logging';

    public static function settingsDescription(): string
    {
        return __('Debugging: switch the module log on and choose how long the logs and bell notifications are kept.');
    }

    public static function keys(): array
    {
        return ['debug.module_log', 'debug.module_log_days', 'debug.email_log_days', 'debug.activity_log_days', 'debug.notification_days'];
    }

    public function form(Schema $schema): Schema
    {
        $days = fn (string $field) => TextInput::make($field)
            ->suffix(__('days'))
            ->required()
            ->integer()
            ->minValue(1)
            ->maxValue(3650);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Module log'))
                    ->description(__('Every request GaiaCP sends to cPanel/WHM, Plesk, HestiaCP, DirectAdmin, easy.gr, Enom and ΑΑΔΕ, with the reply, as in WHMCS. Passwords, API tokens and EPP codes are masked.'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('debug.module_log')
                            ->label('Log module calls')
                            ->helperText(__('Switch it on while you look into a problem with a server or registrar, then off again.'))
                            ->columnSpanFull(),
                        $days('debug.module_log_days')->label('Keep module log entries for')
                            ->helperText(__('Older entries are deleted every night.')),
                    ])
                    ->footerActions([Action::make('openModuleLog')->label('Open the module log')->link()->url(fn () => ModuleLogResource::getUrl())]),
                Section::make(__('Email log'))
                    ->description(__('A copy of every email sent, and of every one that failed, is always kept.'))
                    ->columns(2)
                    ->schema([
                        $days('debug.email_log_days')->label('Keep sent emails for')
                            ->helperText(__('Older entries are deleted every night.')),
                    ])
                    ->footerActions([Action::make('openEmailLog')->label('Open the email log')->link()->url(fn () => EmailLogResource::getUrl())]),
                Section::make(__('Activity log and notifications'))
                    ->description(__('The activity log records every change and automated action. Leave a field empty to keep everything.'))
                    ->columns(2)
                    ->schema([
                        $days('debug.activity_log_days')->label('Keep activity log entries for')
                            ->required(false)
                            ->minValue(30)
                            ->placeholder(__('For ever'))
                            ->helperText(__('Older entries are deleted every night.')),
                        $days('debug.notification_days')->label('Keep bell notifications for')
                            ->required(false)
                            ->placeholder(__('For ever'))
                            ->helperText(__('Admins\' and clients\', read or not.')),
                    ])
                    ->footerActions([Action::make('openActivityLog')->label('Open the activity log')->link()->url(fn () => ActivityLogResource::getUrl())]),
                Section::make(__('Error log'))
                    ->description(__('Laravel\'s own log (storage/logs) with errors and warnings: see Error log.'))
                    ->footerActions([Action::make('openErrorLog')->label('Open the error log')->link()->url(fn () => ErrorLog::getUrl())]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['debug.module_log'] = (bool) $values['debug.module_log'];

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['debug.module_log'] = (bool) $values['debug.module_log'];
        $values['debug.module_log_days'] = (int) $values['debug.module_log_days'];
        $values['debug.email_log_days'] = (int) $values['debug.email_log_days'];
        $values['debug.activity_log_days'] = filled($values['debug.activity_log_days'] ?? null) ? (int) $values['debug.activity_log_days'] : null;
        $values['debug.notification_days'] = filled($values['debug.notification_days'] ?? null) ? (int) $values['debug.notification_days'] : null;

        return $values;
    }
}
