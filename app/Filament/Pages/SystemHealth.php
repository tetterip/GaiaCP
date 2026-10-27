<?php

namespace App\Filament\Pages;

use App\Backups\RunBackup;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\Health\Check;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth as Health;
use App\Support\Health\TableSizes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * WHMCS-style System health: PHP and extensions, configuration, database, storage, cron and the daily
 * jobs, mail and connections (App\Support\Health\SystemHealth), then the biggest tables (TableSizes).
 * Under Settings → System. Panels are called only on Test connections, so opening the page stays fast.
 */
class SystemHealth extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 93;

    protected static ?string $title = 'System health';

    public static function settingsDescription(): string
    {
        return __('PHP, database, storage, cron and the daily jobs, mail and server connections.');
    }

    /** Results of the last Test connections, as arrays so Livewire can keep them between requests. */
    public array $connections = [];

    /** @var Collection<int, Check>|null */
    private ?Collection $checks = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnections')
                ->label('Test connections')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (Health $health) {
                    $this->connections = $health->serverChecks()
                        ->map(fn (Check $c) => ['name' => $c->name, 'status' => $c->status->value, 'value' => $c->value, 'hint' => $c->hint])
                        ->all();
                    $this->recheck();
                }),
            Action::make('refresh')
                ->label('Check again')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->recheck()),
        ];
    }

    private function recheck(): void
    {
        $this->checks = null;
        $this->flushCachedTableRecords();
    }

    /** @return Collection<int, Check> */
    public function checks(): Collection
    {
        return $this->checks ??= app(Health::class)->checks()->concat(
            collect($this->connections)->map(fn (array $c) => new Check(__('Connections'), $c['name'], Status::from($c['status']), $c['value'], $c['hint'])),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->checks()
                ->mapWithKeys(fn (Check $c, int $i) => [$i => [
                    'group' => $c->group,
                    'name' => $c->name,
                    'status' => $c->status,
                    'value' => $c->value,
                    'hint' => $c->hint,
                ]])
                ->all())
            ->columns([
                TextColumn::make('name')->label('Check')->weight('medium'),
                TextColumn::make('value')->label('Found')->description(fn (array $record) => $record['hint'])->wrap(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (Status $state) => $state->label())
                    ->badge()
                    ->color(fn (Status $state) => $state->color()),
            ])
            ->defaultGroup(Group::make('group')->titlePrefixedWithLabel(false))
            ->groupingSettingsHidden()
            ->paginated(false);
    }

    public function content(Schema $schema): Schema
    {
        $errors = $this->checks()->where('status', Status::Error)->count();
        $warnings = $this->checks()->where('status', Status::Warning)->count();

        return $schema->components([
            match (true) {
                $errors > 0 => Callout::make(__(':count problem(s) need attention', ['count' => $errors]))->danger(),
                $warnings > 0 => Callout::make(__(':count warning(s), nothing broken', ['count' => $warnings]))->warning(),
                default => Callout::make(__('Everything looks fine'))->success(),
            },
            EmbeddedTable::make(),
            Section::make(__('Biggest tables'))
                ->description(__('What makes the database and its backups big. Logs are trimmed every night as Settings → Logging says.'))
                ->collapsible()
                ->schema([
                    View::make('filament.settings.table-sizes')->viewData(fn () => [
                        'tables' => app(TableSizes::class)->biggest(),
                        'emptyInBackups' => config('gaiacp.backups.include_logs') ? [] : RunBackup::LOG_TABLES,
                    ]),
                ]),
        ]);
    }
}
