<?php

namespace App\Filament\Resources\Currencies;

use App\Enums\AdminArea;
use App\Enums\CurrencyFormat;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Support\Currencies;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Settings → Currencies: the base currency (gaiacp.currency, rate 1) and the others clients can be billed in, each
 * with how its amounts are written and its rate to the base one. Header actions update the rates from the ECB and
 * the prices in other currencies (App\Support\ExchangeRates).
 */
class CurrencyResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 23;

    protected static ?string $navigationLabel = 'Currencies';

    protected static ?string $modelLabel = 'currency';

    protected static ?string $pluralModelLabel = 'Currencies';

    protected static ?string $slug = 'currencies';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'code';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('The currencies clients are billed in, how amounts are written and the exchange rates.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Currency code')
                    ->required()
                    ->regex('/^[A-Za-z]{3}$/')
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                    ->disabledOn('edit')
                    ->placeholder('USD')
                    ->helperText(__('Three letters, e.g. USD or GBP.')),
                Select::make('format')
                    ->options(CurrencyFormat::class)
                    ->default(CurrencyFormat::Locale)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->helperText(__('By language writes amounts as the reader\'s language does (€1,234.56 in English, 1.234,56 € in Greek) and ignores prefix and suffix.')),
                TextInput::make('prefix')
                    ->maxLength(10)
                    ->placeholder('$'),
                TextInput::make('suffix')
                    ->maxLength(10)
                    ->placeholder('USD'),
                TextInput::make('rate')
                    ->label('Base conversion rate')
                    ->required()
                    ->numeric()
                    ->minValue(0.00001)
                    ->maxValue(9999999999)
                    ->default(1)
                    ->disabled(fn (?Currency $record) => (bool) $record?->isBase())
                    ->helperText(fn () => __('How many of this currency one :base buys. The base currency\'s is always 1.', ['base' => Currencies::base()])),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Currency code')
                    ->weight('medium')
                    ->description(fn (Currency $record) => $record->isBase() ? __('Base currency') : null),
                TextColumn::make('prefix')
                    ->placeholder('-'),
                TextColumn::make('suffix')
                    ->placeholder('-'),
                TextColumn::make('format')
                    ->description(fn (Currency $record) => __('e.g. :amount', ['amount' => Money::format(123456, $record->code)])),
                TextColumn::make('rate')
                    ->label('Base conversion rate')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 5, '.', '')),
            ])
            ->defaultSort('code')
            ->paginated(false)
            ->recordActions([
                EditAction::make()
                    ->after(fn (Currency $record) => ActivityLog::record('currency.updated', "Currency {$record->code} updated (rate {$record->rate})")),
                DeleteAction::make()
                    ->hidden(fn (Currency $record) => $record->isBase() || $record->inUse())
                    ->after(fn (Currency $record) => ActivityLog::record('currency.deleted', "Currency {$record->code} deleted")),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencies::route('/'),
        ];
    }
}
