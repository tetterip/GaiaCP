<?php

namespace App\Filament\Resources\ModuleLogs\Pages;

use App\Filament\Clusters\Settings\Pages\LoggingSettings;
use App\Filament\Resources\ModuleLogs\ModuleLogResource;
use App\Models\ModuleLog;
use App\Support\ModuleLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListModuleLogs extends ListRecords
{
    protected static string $resource = ModuleLogResource::class;

    public function getSubheading(): ?string
    {
        return ModuleLogger::enabled()
            ? __('Logging is on. Entries are kept for :days days.', ['days' => (int) config('gaiacp.debug.module_log_days')])
            : __('Logging is off, so nothing new is recorded. Switch it on under Settings → Logging while you look into a problem.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label(ModuleLogger::enabled() ? 'Logging settings' : 'Switch logging on')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color(ModuleLogger::enabled() ? 'gray' : 'primary')
                ->url(LoggingSettings::getUrl()),
            Action::make('clear')
                ->label('Clear log')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('Deletes every module log entry.'))
                ->visible(fn () => ModuleLog::query()->exists())
                ->action(function () {
                    ModuleLog::query()->delete();
                    Notification::make()->success()->title(__('Module log cleared'))->send();
                }),
        ];
    }
}
