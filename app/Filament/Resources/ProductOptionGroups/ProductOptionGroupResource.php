<?php

namespace App\Filament\Resources\ProductOptionGroups;

use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\EditProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\ListProductOptionGroups;
use App\Models\ProductOptionGroup;
use App\Support\Currencies;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings → Product options: groups of options (a list, buttons, yes/no or a quantity) attached to products, each
 * choice with a price per cycle and a setup fee. New order and the service form offer them; the service keeps what was
 * picked and its price includes them (App\Support\ProductOptions).
 */
class ProductOptionGroupResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ProductOptionGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Product options';

    protected static ?string $modelLabel = 'option group';

    protected static ?string $pluralModelLabel = 'Product options';

    protected static ?string $slug = 'product-options';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Options offered with products, such as a location or extra IPs, each with its own price per cycle.');
    }

    public static function form(Schema $schema): Schema
    {
        $type = fn (Get $get, string $path = 'type') => $get($path);

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText(__('Only for you, e.g. "VPS options".')),
                    Select::make('products')
                        ->relationship('products', 'name')
                        ->multiple()
                        ->preload()
                        ->helperText(__('Offered with these products.')),
                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Repeater::make('options')
                ->columnSpanFull()
                ->columns(2)
                ->collapsible()
                ->reorderableWithButtons()
                ->addActionLabel(__('Add option'))
                ->itemLabel(fn (array $state) => $state['name'] ?? null)
                ->schema([
                    Hidden::make('id')
                        ->default(fn () => (string) Str::uuid()),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText(__('As clients see it, e.g. "Location" or "Extra IP addresses".')),
                    Select::make('type')
                        ->options([
                            'select' => __('List'),
                            'radio' => __('Buttons'),
                            'yesno' => __('Yes / no'),
                            'quantity' => __('Quantity'),
                        ])
                        ->default('select')
                        ->required()
                        ->live(),
                    TextInput::make('min')
                        ->label('Minimum')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->visible(fn (Get $get) => $type($get) === 'quantity'),
                    TextInput::make('max')
                        ->label('Maximum')
                        ->integer()
                        ->minValue(1)
                        ->visible(fn (Get $get) => $type($get) === 'quantity'),
                    Toggle::make('hidden')
                        ->label('Hidden from clients')
                        ->helperText(__('Only you pick it, on New order and the service. Left out of the client\'s payment notice text.'))
                        ->columnSpanFull(),
                    Repeater::make('choices')
                        ->label(fn (Get $get) => in_array($type($get), ['yesno', 'quantity'], true) ? __('Price') : __('Choices'))
                        ->columnSpanFull()
                        ->minItems(1)
                        ->maxItems(fn (Get $get) => in_array($type($get), ['yesno', 'quantity'], true) ? 1 : null)
                        ->defaultItems(1)
                        ->reorderableWithButtons()
                        ->addActionLabel(__('Add choice'))
                        ->itemLabel(fn (array $state) => $state['name'] ?? null)
                        ->schema([
                            Hidden::make('id')
                                ->default(fn () => (string) Str::uuid()),
                            TextInput::make('name')
                                ->required(fn (Get $get) => ! in_array($type($get, '../../type'), ['yesno', 'quantity'], true))
                                ->visible(fn (Get $get) => ! in_array($type($get, '../../type'), ['yesno', 'quantity'], true))
                                ->maxLength(255),
                            Fieldset::make(fn (Get $get) => $type($get, '../../type') === 'quantity' ? __('Per unit') : __('Price'))
                                ->columns(['default' => 2, 'md' => 4])
                                ->schema([
                                    self::money('prices.'.BillingCycle::Monthly->value, BillingCycle::Monthly->getLabel())
                                        ->required()
                                        ->live(onBlur: true),
                                    ...collect(BillingCycle::cases())->reject(fn (BillingCycle $cycle) => $cycle === BillingCycle::Monthly)
                                        ->map(fn (BillingCycle $cycle) => self::money("prices.{$cycle->value}", $cycle->getLabel())
                                            ->placeholder(fn (Get $get) => self::times($get('prices.'.BillingCycle::Monthly->value), $cycle)))
                                        ->all(),
                                    self::money('setup_cents', __('Setup fee'))
                                        ->placeholder('0.00'),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('options')
                    ->label('Options')
                    ->state(fn (ProductOptionGroup $record) => collect($record->options ?? [])->pluck('name')->filter()->all())
                    ->listWithLineBreaks()
                    ->placeholder(__('None')),
                TextColumn::make('products.name')
                    ->label('Products')
                    ->listWithLineBreaks()
                    ->placeholder(__('None')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOptionGroups::route('/'),
            'create' => CreateProductOptionGroup::route('/create'),
            'edit' => EditProductOptionGroup::route('/{record}/edit'),
        ];
    }

    /** Empty = the monthly price times the cycle's months: what that is, as a placeholder. */
    private static function times(mixed $monthly, BillingCycle $cycle): ?string
    {
        try {
            return filled($monthly) ? Money::toDecimal(Money::toCents($monthly) * $cycle->months()) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix(Currencies::base())
            ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toDecimal((int) $state))
            ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null);
    }
}
