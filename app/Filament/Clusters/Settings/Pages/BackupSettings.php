<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Backups\FtpUploader;
use App\Backups\RunBackup;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Models\ActivityLog;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Throwable;
use UnitEnum;

class BackupSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 92;

    protected static ?string $title = 'Backups';

    protected static ?string $slug = 'backups';

    public static function settingsDescription(): string
    {
        return __('Zipped database backups on a schedule, copied to FTP and email.');
    }

    public static function keys(): array
    {
        return [
            'backups.enabled', 'backups.frequency', 'backups.weekday', 'backups.time', 'backups.keep', 'backups.zip_password', 'backups.include_logs', 'backups.email',
            'backups.ftp.enabled', 'backups.ftp.protocol', 'backups.ftp.host', 'backups.ftp.port', 'backups.ftp.username', 'backups.ftp.password',
            'backups.ftp.path', 'backups.ftp.passive',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Schedule'))
                    ->description(__('A zipped SQL dump of the whole database, kept on this server (storage/app/private/backups) and copied to FTP and/or email.'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('backups.enabled')
                            ->label('Back up automatically')
                            ->live()
                            ->columnSpanFull(),
                        Select::make('backups.frequency')
                            ->label('How often')
                            ->options(['daily' => __('Every day'), 'weekly' => __('Once a week')])
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                        Select::make('backups.weekday')
                            ->label('On')
                            ->options([1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday')])
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get) => $get('backups.frequency') === 'weekly')
                            ->required(),
                        TextInput::make('backups.time')
                            ->label('At')
                            ->required()
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                            ->placeholder('03:00')
                            ->helperText(__('24-hour time, :timezone.', ['timezone' => config('app.timezone')])),
                        TextInput::make('backups.keep')
                            ->label('Keep the newest')
                            ->suffix(__('backups'))
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(365)
                            ->helperText(__('Older ones are deleted here and on the FTP server.')),
                        TextInput::make('backups.zip_password')
                            ->label('Zip password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->placeholder(fn () => config('gaiacp.backups.zip_password') ? __('Set (leave blank to keep it)') : __('None'))
                            ->helperText(__('Encrypts the zip (AES-256; open it with 7-Zip or WinRAR). Recommended, since the backup holds every client\'s details. Keep the password somewhere safe: without it the backup cannot be opened.'))
                            ->columnSpanFull(),
                        Toggle::make('backups.include_logs')
                            ->label('Include logs')
                            ->helperText(__('Off: the module log, the email log (and so the clients\' Email history), sessions, cache and queued jobs go into the backup empty. They can be most of the database and are not needed to restore it.'))
                            ->columnSpanFull(),
                        Text::make(fn () => $this->backupSummary())->columnSpanFull(),
                        Actions::make([
                            Action::make('backupNow')
                                ->label('Back up now')
                                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                                ->requiresConfirmation()
                                ->modalDescription(__('Uses the saved settings: save first if you changed anything.'))
                                ->action(fn () => $this->backupNow()),
                            Action::make('downloadBackup')
                                ->label('Download latest')
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->color('gray')
                                ->visible(fn () => RunBackup::localBackups() !== [])
                                ->action(fn () => response()->download(RunBackup::localBackups()[0])),
                        ])->key('backupActions')->columnSpanFull(),
                    ]),
                Section::make(__('Email'))
                    ->schema([
                        TextInput::make('backups.email')
                            ->label('Email each backup to')
                            ->email()
                            ->maxLength(255)
                            ->helperText(__('Leave empty to not email it. Zips over :size are not attached; failures are emailed here too.', ['size' => Number::fileSize(RunBackup::MAX_ATTACHMENT_BYTES)])),
                    ]),
                Section::make(__('FTP'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('backups.ftp.enabled')
                            ->label('Upload each backup to an FTP server')
                            ->live()
                            ->columnSpanFull(),
                        Select::make('backups.ftp.protocol')
                            ->label('Protocol')
                            ->options(['ftp' => 'FTP', 'ftps' => __('FTPS (FTP over explicit TLS)')])
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('backups.ftp.host')
                            ->label('Host')
                            ->maxLength(255)
                            ->required(fn (Get $get) => (bool) $get('backups.ftp.enabled')),
                        TextInput::make('backups.ftp.port')
                            ->label('Port')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->placeholder('21'),
                        TextInput::make('backups.ftp.username')
                            ->label('Username')
                            ->autocomplete('off')
                            ->maxLength(255)
                            ->required(fn (Get $get) => (bool) $get('backups.ftp.enabled')),
                        TextInput::make('backups.ftp.password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->placeholder(fn () => config('gaiacp.backups.ftp.password') ? __('Set (leave blank to keep it)') : null),
                        TextInput::make('backups.ftp.path')
                            ->label('Folder')
                            ->maxLength(255)
                            ->placeholder('/backups')
                            ->helperText(__('Created if missing.')),
                        Toggle::make('backups.ftp.passive')
                            ->label('Passive mode')
                            ->helperText(__('Leave on unless the server needs active mode.')),
                        Actions::make([
                            Action::make('testFtp')
                                ->label('Test FTP')
                                ->icon(Heroicon::OutlinedSignal)
                                ->color('gray')
                                ->action(fn () => $this->testFtp()),
                        ])->key('ftpActions')->columnSpanFull(),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['backups.enabled'] = (bool) $values['backups.enabled'];
        $values['backups.include_logs'] = (bool) $values['backups.include_logs'];
        $values['backups.ftp.enabled'] = (bool) $values['backups.ftp.enabled'];
        $values['backups.ftp.passive'] = (bool) $values['backups.ftp.passive'];

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['backups.enabled'] = (bool) $values['backups.enabled'];
        $values['backups.weekday'] = (int) ($values['backups.weekday'] ?? config('gaiacp.backups.weekday'));
        $values['backups.keep'] = (int) $values['backups.keep'];
        $values['backups.include_logs'] = (bool) $values['backups.include_logs'];
        $values['backups.ftp.enabled'] = (bool) $values['backups.ftp.enabled'];
        $values['backups.ftp.passive'] = (bool) $values['backups.ftp.passive'];
        $values['backups.ftp.port'] = (int) $values['backups.ftp.port'] ?: 21;

        return $values;
    }

    private function backupSummary(): string
    {
        $last = ActivityLog::query()->whereIn('event', ['backup.created', 'backup.failed'])->latest('id')->first();
        $local = count(RunBackup::localBackups());

        return ($last
            ? __('Last run :date: :result.', ['date' => $last->created_at->format(AppServiceProvider::dateFormat().' H:i'), 'result' => $last->description])
            : __('No backup yet.')).' '.__('Backups on this server: :count.', ['count' => $local]);
    }

    private function backupNow(): void
    {
        $result = app(RunBackup::class)();

        $notification = Notification::make()
            ->title($result->created() ? __('Backup created (:size)', ['size' => $result->sizeForHumans()]) : __('Backup failed'))
            ->body(implode(' ', array_filter([
                $result->copies ? __('Sent to :copies.', ['copies' => implode(' '.__('and').' ', $result->copies)]) : null,
                ...array_map(fn ($copy, $error) => ($copy === 'backup' ? '' : strtoupper($copy).': ').$error, array_keys($result->errors), $result->errors),
            ])) ?: null);

        ($result->ok() ? $notification->success() : $notification->danger()->persistent())->send();
    }

    private function testFtp(): void
    {
        // The values on screen, so it can be tried before saving; a blank password means the stored one.
        $ftp = (array) data_get($this->data, 'backups.ftp', []);
        $ftp['password'] = ($ftp['password'] ?? null) ?: config('gaiacp.backups.ftp.password');

        try {
            app(FtpUploader::class, ['config' => $ftp])->test();
            Notification::make()->success()->title(__('FTP connection works'))->body(__('Logged in and opened :path.', ['path' => ($ftp['path'] ?? null) ?: '/']))->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('FTP connection failed'))->body($e->getMessage())->persistent()->send();
        }
    }
}
