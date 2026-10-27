<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\LogReader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Laravel's own log (storage/logs), newest entry first, under Settings → System: PHP errors, failed jobs and
 * whatever the app logs with Log::error(). Each entry opens with its stack trace; the file can be downloaded or cleared.
 */
class ErrorLog extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 103;

    protected static ?string $title = 'Error log';

    protected static ?string $slug = 'error-log';

    public static function settingsDescription(): string
    {
        return __('Laravel\'s log of errors and warnings, with stack traces, to download or clear.');
    }

    public function getSubheading(): ?string
    {
        return __('Newest first. Large files show their last :size; download the file for all of it.', ['size' => LogReader::size(LogReader::TAIL_BYTES)]);
    }

    private function reader(): LogReader
    {
        return app(LogReader::class);
    }

    /** The file picked in the filter, else the newest. */
    public function currentFile(): ?string
    {
        $files = $this->reader()->files();
        $picked = $this->tableFilters['file']['value'] ?? null;

        return isset($files[$picked]) ? $picked : array_key_first($files);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->currentFile() !== null)
                ->action(fn (): ?BinaryFileResponse => ($path = $this->reader()->path($this->currentFile())) ? response()->download($path) : null),
            Action::make('clear')
                ->label('Clear file')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(fn () => __('Empties :file. Download it first if you may need it.', ['file' => $this->currentFile()]))
                ->visible(fn () => $this->currentFile() !== null)
                ->action(function () {
                    $this->reader()->clear((string) $this->currentFile());
                    $this->flushCachedTableRecords();
                    Notification::make()->success()->title(__(':file cleared', ['file' => $this->currentFile()]))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters, ?string $search, int|string $page, int|string $recordsPerPage) {
                $file = $this->currentFile();
                $levels = (array) ($filters['level']['values'] ?? []);

                $entries = ($file ? $this->reader()->entries($file) : collect())
                    ->when($levels !== [], fn ($c) => $c->whereIn('level', $levels))
                    ->when(filled($search), fn ($c) => $c->filter(fn (array $e) => str_contains(mb_strtolower($e['message'].$e['details']), mb_strtolower($search))));

                $perPage = $recordsPerPage === 'all' ? max(1, $entries->count()) : (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    $entries->forPage((int) $page, $perPage)->keyBy('key')->all(),
                    $entries->count(),
                    $perPage,
                    (int) $page,
                );
            })
            ->columns([
                TextColumn::make('at')->label('When'),
                TextColumn::make('level')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'emergency', 'alert', 'critical', 'error' => 'danger',
                        'warning' => 'warning',
                        'notice', 'info' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('message')->wrap()->limit(200)->extraAttributes(['style' => 'overflow-wrap: anywhere']),
            ])
            ->searchable()
            ->filters([
                SelectFilter::make('file')
                    ->options(fn () => $this->reader()->files())
                    ->default(fn () => array_key_first($this->reader()->files()))
                    ->selectablePlaceholder(false),
                SelectFilter::make('level')
                    ->multiple()
                    ->options(collect(LogReader::LEVELS)->mapWithKeys(fn (string $l) => [$l => ucfirst($l)])->all()),
            ])
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (array $record) => ucfirst($record['level']).' · '.$record['at'])
                    ->modalContent(fn (array $record) => view('filament.logs.error-log-entry', ['entry' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('5xl'),
            ])
            ->emptyStateHeading(__('No entries'))
            ->emptyStateDescription(__('Nothing has been logged in this file.'))
            ->defaultPaginationPageOption(25);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
