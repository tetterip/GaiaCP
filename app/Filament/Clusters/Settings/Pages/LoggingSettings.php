<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Pages\ErrorLog;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Filament\Resources\ModuleLogs\ModuleLogResource;
use App\Support\LogCleanup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Debugging options: the module log switch and how long the module, email and activity logs and the bell notifications are
 * kept; super admins also clean up by hand (App\Support\LogCleanup): old log rows, files of closed tickets, the cache.
 */
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
                Section::make(__('Clean up'))
                    ->description(__('Make the database or the disk smaller now, without waiting for the nightly run. What is deleted is gone for good; backups made before still have it.'))
                    ->visible(fn () => self::cleansUp())
                    ->schema([
                        Actions::make(collect(LogCleanup::logs())->map(fn (string $name, string $log) => $this->deleteLogAction($log, $name))->values()->all())
                            ->key('cleanupLogs'),
                        Actions::make([
                            Action::make('deleteTicketFiles')
                                ->label('Delete files of closed tickets')
                                ->icon(Heroicon::OutlinedPaperClip)
                                ->color('gray')
                                ->badge(fn () => Number::format(app(LogCleanup::class)->attachments()->count()))
                                ->modalHeading(__('Delete files of closed tickets'))
                                ->modalDescription(fn () => __('Files of closed tickets now take :size. The messages stay and name each file as deleted. Settings → Support can do this every night.', ['size' => Number::fileSize((int) app(LogCleanup::class)->attachments()->sum('size'), precision: 1)]))
                                ->schema([
                                    DatePicker::make('before')
                                        ->label('Tickets closed before')
                                        ->default(now()->subDays(90)->toDateString())
                                        ->maxDate(now()->toDateString())
                                        ->required(),
                                ])
                                ->modalSubmitActionLabel(__('Delete'))
                                ->action(function (array $data) {
                                    abort_unless(self::cleansUp(), 403);
                                    $result = app(LogCleanup::class)->deleteAttachments(Carbon::parse($data['before'])->startOfDay(), manual: true);
                                    Notification::make()->success()
                                        ->title(__(':count file(s) deleted, :size freed', ['count' => $result['files'], 'size' => Number::fileSize($result['bytes'], precision: 1)]))
                                        ->send();
                                }),
                            Action::make('clearCache')
                                ->label('Empty the cache')
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription(__('Deletes the stored cache and the compiled page templates. Both fill again as pages are opened, so the next pages may open a little slower. Useful after changing files on the server by hand.'))
                                ->action(function () {
                                    abort_unless(self::cleansUp(), 403);
                                    app(LogCleanup::class)->clearCache();
                                    Notification::make()->success()->title(__('Cache emptied'))->send();
                                }),
                        ])->key('cleanupOther'),
                    ]),
            ]);
    }

    private static function cleansUp(): bool
    {
        return (bool) auth()->user()?->super_admin;
    }

    private function deleteLogAction(string $log, string $name): Action
    {
        return Action::make('clean_'.$log)
            ->label($name)
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->badge(fn () => Number::format(app(LogCleanup::class)->count($log)))
            ->modalHeading(__('Delete old entries: :log', ['log' => __($name)]))
            ->modalDescription($log === 'activity'
                ? __('The activity log is the record of every change, so only entries older than :days days can be deleted.', ['days' => LogCleanup::ACTIVITY_MIN_DAYS])
                : __('Entries written before the day you choose are deleted.'))
            ->schema([
                DatePicker::make('before')
                    ->label('Delete entries older than')
                    ->default(fn () => app(LogCleanup::class)->defaultBefore($log)->toDateString())
                    ->maxDate(fn () => LogCleanup::latestBefore($log)->toDateString())
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Delete'))
            ->action(function (array $data) use ($log) {
                abort_unless(self::cleansUp(), 403);
                $before = Carbon::parse($data['before'])->startOfDay();
                if ($before->greaterThan(LogCleanup::latestBefore($log))) {
                    Notification::make()->danger()->title(__('Choose an earlier day'))->send();

                    return;
                }
                $deleted = app(LogCleanup::class)->deleteBefore($log, $before);
                Notification::make()->success()->title(__(':count entries deleted', ['count' => Number::format($deleted)]))->send();
            });
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
