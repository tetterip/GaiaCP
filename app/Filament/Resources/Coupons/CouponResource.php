<?php

namespace App\Filament\Resources\Coupons;

use App\Enums\AdminArea;
use App\Enums\CouponType;
use App\Enums\ServiceType;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Tld;
use App\Providers\AppServiceProvider;
use App\Support\Currencies;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Billing → Coupons: codes clients enter in the cart (or an admin picks on New order) for a percentage or a fixed
 * amount off, on the first payment only or on every renewal too, for chosen products and domain endings or for
 * everything, between two dates, with a usage limit (App\Models\Coupon, App\Support\Coupons). A coupon that was used
 * is never deleted: switch it off instead.
 */
class CouponResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'code';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Sales;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[\pL\pN_-]+$/u')
                    ->unique(ignoreRecord: true)
                    ->default(fn () => Str::upper(Str::random(8)))
                    ->helperText(__('Letters, numbers, - and _. Clients type it in any case.')),
                TextInput::make('description')
                    ->label('Note')
                    ->maxLength(255)
                    ->helperText(__('For the admins only, e.g. the campaign it is for.')),
                Select::make('type')
                    ->options(CouponType::class)
                    ->default(CouponType::Percent)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
                TextInput::make('value')
                    ->label(fn (Get $get) => self::isPercent($get) ? __('Percentage') : __('Amount'))
                    ->required()
                    ->suffix(fn (Get $get) => self::isPercent($get) ? '%' : null)
                    ->prefix(fn (Get $get) => self::isPercent($get) ? null : Currencies::base())
                    ->rules(fn (Get $get) => self::isPercent($get) ? ['integer', 'min:1', 'max:100'] : ['regex:/^\d+([.,]\d{1,2})?$/'])
                    ->formatStateUsing(fn ($state, Get $get) => $state === null || self::isPercent($get) ? $state : Money::toDecimal((int) $state))
                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isPercent($get) ? (int) $state : Money::toCents((string) $state))
                    ->helperText(fn (Get $get) => self::isPercent($get) ? null : (Currencies::multiple()
                        ? __('Off each item it covers; in the base currency, converted for clients in other currencies.')
                        : __('Off each item it covers.'))),
                Toggle::make('recurring')
                    ->label('Renewals too')
                    ->helperText(__('Off: only the first payment is lower. On: the service keeps the lower price at every renewal.'))
                    ->inline(false)
                    ->columnSpanFull(),
            ]),
            Section::make(__('What it covers'))
                ->description(__('Leave both empty for everything. A setup fee is never lowered.'))
                ->compact()
                ->schema([
                    Select::make('product_ids')
                        ->label('Products')
                        ->multiple()
                        ->options(fn () => Product::query()->selectable()->where('type', '!=', ServiceType::Domain)->orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                    Select::make('tlds')
                        ->label('Domains ending in')
                        ->multiple()
                        ->options(fn () => Tld::query()->orderBy('tld')->pluck('tld', 'tld'))
                        ->helperText(__('Lowers the registration or transfer price (and the renewal price with Renewals too).')),
                    Toggle::make('on_sale')
                        ->label('Also on sale prices')
                        ->helperText(__('Off: products and domains on sale keep their sale price and the coupon skips them. On: it lowers the sale price too.')),
                ]),
            Section::make(__('Limits'))
                ->compact()
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    DatePicker::make('starts_on')
                        ->label('Valid from'),
                    DatePicker::make('ends_on')
                        ->label('Valid until')
                        ->afterOrEqual('starts_on'),
                    TextInput::make('max_uses')
                        ->label('Usage limit')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->placeholder(__('No limit'))
                        ->helperText(__('Orders that may use it in total; a cancelled order gives its use back.')),
                    Toggle::make('once_per_client')
                        ->label('Once per client')
                        ->inline(false),
                    Toggle::make('new_clients_only')
                        ->label('New clients only')
                        ->helperText(__('Clients without an earlier order or service.'))
                        ->inline(false),
                    Toggle::make('active')
                        ->default(true)
                        ->helperText(__('Off: it can no longer be used; orders that used it keep their prices.'))
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->description(fn (Coupon $record) => $record->description),
                TextColumn::make('value')
                    ->label('Discount')
                    ->state(fn (Coupon $record) => $record->recurring
                        ? __(':value, renewals too', ['value' => $record->describeValue()])
                        : __(':value, first payment', ['value' => $record->describeValue()])),
                TextColumn::make('covers')
                    ->label('Covers')
                    ->state(fn (Coupon $record) => $record->coversAll() ? __('Everything') : collect([
                        ...Product::query()->whereIn('id', $record->product_ids ?? [])->orderBy('name')->pluck('name')->all(),
                        ...($record->tlds ?? []),
                    ])->join(', '))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('uses_count')
                    ->label('Used')
                    ->counts('uses')
                    ->formatStateUsing(fn (int $state, Coupon $record) => $record->max_uses ? "{$state} / {$record->max_uses}" : (string) $state),
                TextColumn::make('ends_on')
                    ->label('Valid')
                    ->state(fn (Coupon $record) => self::validity($record))
                    ->color(fn (Coupon $record) => $record->ends_on && $record->ends_on->lt(CarbonImmutable::today()) ? 'danger' : null),
                IconColumn::make('active')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('3xl'),
                DeleteAction::make()
                    // Orders keep which coupon they used.
                    ->hidden(fn (Coupon $record) => $record->orders()->exists()),
            ])
            ->emptyStateHeading(__('No coupons yet'))
            ->emptyStateDescription(__('Add a code clients enter in the cart for a discount on their order.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCoupons::route('/'),
        ];
    }

    private static function isPercent(Get $get): bool
    {
        $type = $get('type');

        return ($type instanceof CouponType ? $type : CouponType::tryFrom((string) $type)) !== CouponType::Fixed;
    }

    private static function validity(Coupon $record): string
    {
        $format = fn (CarbonImmutable $date) => $date->format(AppServiceProvider::dateFormat());

        return match (true) {
            $record->starts_on && $record->ends_on => __(':from - :until', ['from' => $format($record->starts_on), 'until' => $format($record->ends_on)]),
            (bool) $record->starts_on => __('From :date', ['date' => $format($record->starts_on)]),
            (bool) $record->ends_on => __('Until :date', ['date' => $format($record->ends_on)]),
            default => __('Always'),
        };
    }
}
