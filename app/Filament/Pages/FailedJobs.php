<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\FailedJob;
use App\Providers\AppServiceProvider;
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
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Settings → Failed jobs: queued jobs that failed every try (Laravel's `failed_jobs`), newest first, with their error.
 * Retry puts one back on its queue (queue:retry; the scheduler's queue:work runs it within a minute); Delete forgets it.
 */
class FailedJobs extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 104;

    protected static ?string $title = 'Failed jobs';

    protected static ?string $slug = 'failed-jobs';

    public static function settingsDescription(): string
    {
        return __('Background jobs that failed, with their error, to try again or delete.');
    }

    public function getSubheading(): ?string
    {
        return __('Jobs run in the background, such as queued emails, that failed every try. Most of GaiaCP runs at once, so this list is usually empty.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')
                ->label('Retry all')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => FailedJob::query()->exists())
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('queue:retry', ['id' => ['all']]);
                    Notification::make()->success()->title(__('Put back on the queue'))->send();
                }),
            Action::make('deleteAll')
                ->label('Delete all')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn () => FailedJob::query()->exists())
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('queue:flush');
                    Notification::make()->success()->title(__('Failed jobs deleted'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i:s')
                    ->sortable(),
                TextColumn::make('payload')
                    ->label('Job')
                    ->formatStateUsing(fn (FailedJob $record) => class_basename($record->jobName()))
                    ->description(fn (FailedJob $record) => $record->queue)
                    ->searchable(),
                TextColumn::make('exception')
                    ->label('Error')
                    ->formatStateUsing(fn (FailedJob $record) => $record->errorLine())
                    ->wrap()
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (FailedJob $record) => $record->jobName())
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (FailedJob $record) => new HtmlString('<pre style="white-space: pre-wrap; word-break: break-all; font-size: 12px; max-height: 32rem; overflow: auto">'.e((string) $record->exception).'</pre>')),
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->success()->title(__('Put back on the queue'))->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:forget', ['id' => $record->uuid]);
                        Notification::make()->success()->title(__('Failed job deleted'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No failed jobs'));
    }
}
