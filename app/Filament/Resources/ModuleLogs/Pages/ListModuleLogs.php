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
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListModuleLogs extends ListRecords
{
    protected static string $resource = ModuleLogResource::class;

    public const DOWNLOAD_LIMIT = 500;

    public function getSubheading(): ?string
    {
        return ModuleLogger::enabled()
            ? __('Logging is on. Entries are kept for :days days.', ['days' => (int) config('gaiacp.debug.module_log_days')])
            : __('Logging is off, so nothing new is recorded. Switch it on under Settings → Logging while you look into a problem.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->tooltip(__('The entries shown (filters and search included, newest :count) as a JSON file, for a bug report. Passwords and tokens are already masked.', ['count' => self::DOWNLOAD_LIMIT]))
                ->visible(fn () => ModuleLog::query()->exists())
                ->action(fn () => $this->download()),
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

    /** The entries the table shows, as JSON: the real answers of a panel or registrar, to check a plugin's test fakes against. */
    public function download(): StreamedResponse
    {
        $entries = $this->getFilteredSortedTableQuery()->reorder()->latest('id')->limit(self::DOWNLOAD_LIMIT)->get()
            ->map(fn (ModuleLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'module' => $log->module,
                'action' => $log->action,
                'status' => $log->status,
                'duration_ms' => $log->duration_ms,
                'url' => $log->url,
                'request' => $log->request,
                'response' => $log->response,
                'error' => $log->error,
            ])->values()->all();

        return response()->streamDownload(
            fn () => print (json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'module-log-'.now()->format('Y-m-d-His').'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
