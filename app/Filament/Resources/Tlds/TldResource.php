<?php

namespace App\Filament\Resources\Tlds;

use App\Enums\AdminArea;
use App\Filament\Actions\ChangePriceAction;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Tld;
use App\Providers\AppServiceProvider;
use App\Support\Currencies;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Settings → Domain pricing: one row per TLD with its prices for every term (Tld::YEARS), the late renewal fees and
 * the preferred registrar, which new orders pre-select and which handles a domain that names no registrar of its own.
 */
class TldResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Tld::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 32;

    protected static ?string $navigationLabel = 'Domain pricing';

    protected static ?string $modelLabel = 'TLD';

    protected static ?string $pluralModelLabel = 'Domain pricing';

    protected static ?string $slug = 'domain-pricing';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'tld';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Prices per TLD for every term, late renewal fees and the preferred registrar of each.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('tld')
                    ->label('TLD')
                    ->required()
                    ->maxLength(63)
                    ->regex('/^\.?[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)*$/iu')
                    // Stored as ".gr" (Tld::tld()), so "GR" is the TLD ".gr" already here.
                    ->rule(fn (?Tld $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $tld = '.'.ltrim(mb_strtolower(trim((string) $value)), '.');

                        if (Tld::query()->where('tld', $tld)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                            $fail(__(':tld is already on the list.', ['tld' => $tld]));
                        }
                    })
                    ->placeholder('.gr'),
                Select::make('registrar_id')
                    ->label('Preferred registrar')
                    ->options(fn (?Tld $record) => Registrar::query()->where(fn ($q) => $q->active()->orWhere('id', $record?->registrar_id))->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => Registrar::query()->active()->where('is_default', true)->value('id'))
                    ->placeholder(__('None'))
                    ->helperText(__('Pre-selected on new orders; a domain can name another one. Email Notifications only emails the admins.')),
                Toggle::make('active')
                    ->label('Offered')
                    ->default(true)
                    ->helperText(__('Off: no new orders. Domains already here keep renewing.')),
                Toggle::make('epp_required')
                    ->label('Transfers need an EPP code')
                    ->default(true),

                Section::make(__('Prices'))
                    ->description(__('Selling prices to the client in :currency. Leave a price empty when that term is not offered: orders show only the terms with a price.', ['currency' => Currencies::base()]))
                    ->columnSpanFull()
                    ->schema([
                        self::priceTable(Currencies::base(), fn (string $column) => self::price($column)),
                    ]),

                Section::make(__('Sale'))
                    ->description(__('Lower registration and transfer prices for domains ordered between these days, for their first term; renewals are at the renew price. Clients see the normal price struck through. Leave a price empty when it is not on sale.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(fn (?Tld $record) => collect($record?->sale_prices)->filter(fn ($sale) => $sale !== null)->isEmpty())
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        DatePicker::make('sale_starts_on')
                            ->label('Sale from')
                            ->placeholder(__('Now')),
                        DatePicker::make('sale_ends_on')
                            ->label('Sale until')
                            ->afterOrEqual('sale_starts_on')
                            ->placeholder(__('Until removed'))
                            ->helperText(__('The last day of the sale; the day after, the normal prices apply again by themselves.')),
                        self::priceTable(Currencies::base(), fn (string $column) => self::price("sale_prices.{$column}")
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $column) {
                                try {
                                    if (filled($value) && (blank($price = $get($column)) || Money::toCents($value) >= Money::toCents($price))) {
                                        $fail(__('A sale price must be lower than the price.'));
                                    }
                                } catch (\InvalidArgumentException) {
                                    // a malformed amount: its own rule says so
                                }
                            }), Tld::SALE_KINDS)->columnSpanFull(),
                    ]),

                Section::make(__('Registrar cost'))
                    ->description(__('What the registrar charges you for each term, in :currency. Optional: with it, the list shows your margin, terms sold at a loss are marked red and the reports count domains\' costs as expenses.', ['currency' => Currencies::base()]))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(fn (?Tld $record) => collect($record?->costs)->filter(fn ($cost) => $cost !== null)->isEmpty())
                    ->schema([
                        self::priceTable(Currencies::base(), fn (string $column) => self::price("costs.{$column}")),
                    ]),

                Section::make(__('Late renewal fees'))
                    ->description(__('Grace fee: owed once a domain has expired unpaid; Redemption fee: added after the grace days. Charged only when Settings → Domains says so.'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        self::price('grace_fee_cents')->hiddenLabel(false)->label('Grace fee'),
                        TextInput::make('grace_days')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365),
                        self::price('redemption_fee_cents')->hiddenLabel(false)->label('Redemption fee'),
                    ]),

                Section::make(__('Prices in other currencies'))
                    ->description(__('For clients billed in another currency. Empty = the price above at that currency\'s rate; Update product prices on Settings → Currencies sets them all at today\'s rates.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn () => Currencies::multiple())
                    ->schema(fn () => collect(Currencies::others())->map(fn (string $code) => Grid::make(1)
                        ->schema([
                            self::priceTable($code, fn (string $column) => self::otherPrice($column, $code)),
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                self::otherPrice('grace_fee_cents', $code)->hiddenLabel(false)->label('Grace fee'),
                                self::otherPrice('redemption_fee_cents', $code)->hiddenLabel(false)->label('Redemption fee'),
                            ]),
                        ]))->all()),
            ]);
    }

    /**
     * Terms down, kinds across, the currency in the corner. On a phone the currency and each term label take a row of
     * their own above the three prices, and the inputs drop the currency prefix so the amounts stay readable.
     *
     * @param  callable(string): TextInput  $input
     * @param  list<string>  $kinds  the columns, Tld::KINDS or some of them
     */
    private static function priceTable(string $currency, callable $input, array $kinds = Tld::KINDS): Grid
    {
        $count = count($kinds);
        $label = ['default' => $count, 'sm' => 1];

        return Grid::make(['default' => $count, 'sm' => $count + 1])->schema([
            Text::make($currency)->weight('bold')->columnSpan($label),
            ...array_map(fn (string $kind) => Text::make(self::kindLabel($kind))->weight('medium'), $kinds),
            ...collect(Tld::YEARS)->flatMap(fn (int $years) => [
                Text::make(Tld::termLabel($years))->columnSpan($label),
                ...array_map(fn (string $kind) => $input("{$kind}_{$years}y_cents")->prefix(null), $kinds),
            ])->all(),
        ]);
    }

    /** A price in another currency; its placeholder is the base price at that currency's rate. */
    private static function otherPrice(string $column, string $code): TextInput
    {
        return self::price("currency_prices.{$code}.{$column}", $code)
            ->placeholder(function (Get $get) use ($column, $code) {
                try {
                    return filled($base = $get($column)) ? Money::toDecimal(Currencies::fromBase(Money::toCents($base), $code)) : null;
                } catch (\InvalidArgumentException) {
                    return null;
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tld')
                    ->label('TLD')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('registrar.name')
                    ->label('Preferred registrar')
                    ->placeholder(__('None'))
                    ->sortable(),
                ...array_map(fn (string $kind) => TextColumn::make($kind)
                    ->label(self::kindLabel($kind))
                    ->state(fn (Tld $record) => self::prices($record, $kind))
                    ->placeholder(__('Not offered'))
                    ->listWithLineBreaks(), Tld::KINDS),
                TextColumn::make('margin')
                    ->label('Renewal margin')
                    ->state(fn (Tld $record) => self::margins($record))
                    ->placeholder(__('No cost set'))
                    ->color(fn (Tld $record) => $record->sellsAtALoss() ? 'danger' : null)
                    ->icon(fn (Tld $record) => $record->sellsAtALoss() ? Heroicon::OutlinedExclamationTriangle : null)
                    ->tooltip(fn (Tld $record) => $record->sellsAtALoss() ? __('A term sells for less than the registrar charges.') : null)
                    ->listWithLineBreaks(),
                TextColumn::make('sale_ends_on')
                    ->label('Sale')
                    ->state(fn (Tld $record) => $record->onSale() ? ($record->sale_ends_on ? __('Until :date', ['date' => $record->sale_ends_on->format(AppServiceProvider::dateFormat())]) : __('On sale')) : null)
                    ->badge()
                    ->color('danger')
                    ->toggleable(),
                IconColumn::make('active')
                    ->label('Offered')
                    ->boolean(),
            ])
            ->defaultSort('tld')
            ->filters([
                SelectFilter::make('registrar_id')
                    ->label('Preferred registrar')
                    ->relationship('registrar', 'name'),
            ])
            ->emptyStateHeading(__('No TLDs yet'))
            ->emptyStateDescription(__('Add the TLDs you sell, with their prices and preferred registrar.'))
            ->recordActions([
                EditAction::make()->modalWidth('3xl'),
                ChangePriceAction::forTld(),
                DeleteAction::make()
                    ->modalDescription(__('Its domains already here keep their registrar only if they name one. Turning off "Offered" is usually enough.')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setRegistrar')
                        ->label('Set preferred registrar')
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->schema([
                            Select::make('registrar_id')
                                ->label('Preferred registrar')
                                ->options(fn () => Registrar::query()->active()->orderBy('name')->pluck('name', 'id'))
                                ->placeholder(__('None')),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn (Tld $tld) => $tld->update(['registrar_id' => $data['registrar_id'] ?? null]));
                            ActivityLog::record('tld.registrar_changed', 'Preferred registrar of '.$records->pluck('tld')->join(', ').' set to '.(Registrar::find($data['registrar_id'] ?? null)?->name ?? 'none'));
                            Notification::make()->success()->title(__('Saved'))->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'register' => __('Register'),
            'transfer' => __('Transfer'),
            default => __('Renew'),
        };
    }

    /** @return list<string> "1 year: €18.00" for each priced term */
    private static function prices(Tld $tld, string $kind): array
    {
        return array_map(
            fn (int $years) => Tld::termLabel($years).': '.Money::format((int) $tld->priceCents($kind, $years), Currencies::base()),
            $tld->terms($kind),
        );
    }

    /** @return list<string> "1 year: €4.00 (22%)" for each renewal term with a price and a cost */
    private static function margins(Tld $tld): array
    {
        return collect(Tld::YEARS)
            ->filter(fn (int $years) => $tld->marginCents('renew', $years) !== null)
            ->map(function (int $years) use ($tld) {
                $margin = (int) $tld->marginCents('renew', $years);
                $price = (int) $tld->priceCents('renew', $years);

                return Tld::termLabel($years).': '.Money::format($margin, Currencies::base()).($price > 0 ? ' ('.round($margin * 100 / $price).'%)' : '');
            })
            ->values()
            ->all();
    }

    private static function price(string $column, ?string $currency = null): TextInput
    {
        return TextInput::make($column)
            ->hiddenLabel()
            ->prefix($currency ?? Currencies::base())
            ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toDecimal((int) $state))
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Money::toCents($state));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTlds::route('/'),
        ];
    }
}
