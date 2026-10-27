<?php

namespace App\Filament\Resources\ModuleLogs;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ModuleLogs\Pages\ListModuleLogs;
use App\Models\ModuleLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * WHMCS-style module log (Settings → System): requests to the panels, registrars and ΑΑΔΕ with their replies,
 * written by App\Support\ModuleLogger while Settings → Logging → Module log is on. Read-only.
 */
class ModuleLogResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ModuleLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 101;

    protected static ?string $navigationLabel = 'Module log';

    protected static ?string $modelLabel = 'module log entry';

    protected static ?string $slug = 'module-log';

    protected static bool $isGloballySearchable = false;

    public static function settingsDescription(): string
    {
        return __('Requests to cPanel/WHM, Plesk, HestiaCP, DirectAdmin, easy.gr, Enom and ΑΑΔΕ and what they answered, passwords masked.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(null)->sortable(),
                TextColumn::make('module')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => ModuleLog::modules()[$state] ?? $state),
                TextColumn::make('action')->weight('medium')->searchable(),
                TextColumn::make('url')->label('Request')->limit(60)->color('gray')->searchable(['url', 'request', 'response']),
                TextColumn::make('status')
                    ->label('Result')
                    ->badge()
                    ->state(fn (ModuleLog $record) => $record->error !== null ? __('Error') : (string) $record->status)
                    ->color(fn (ModuleLog $record) => $record->failed() ? 'danger' : 'success')
                    ->tooltip(fn (ModuleLog $record) => $record->error),
                TextColumn::make('duration_ms')->label('Time')->suffix(__(' ms'))->alignEnd()->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('module')->options(fn () => ModuleLog::modules()),
                Filter::make('failed')
                    ->label('Failed only')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNotNull('error')->orWhereNull('status')->orWhere('status', '>=', 400))),
            ])
            ->searchPlaceholder(__('Search action, URL, request or reply'))
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (ModuleLog $record) => (ModuleLog::modules()[$record->module] ?? $record->module).': '.$record->action)
                    ->modalContent(fn (ModuleLog $record) => view('filament.logs.module-log-entry', ['entry' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('5xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuleLogs::route('/'),
        ];
    }
}
