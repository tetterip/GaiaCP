<?php

namespace App\Filament\Resources\ProductTags;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Forms\ColorCircles;
use App\Filament\Resources\ProductTags\Pages\ManageProductTags;
use App\Models\Product;
use App\Models\ProductTag;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Settings → Product tags: free labels on products (ProductTag), such as New or SSD. Visible ones are badges clients
 * see on the order pages; every one filters Products, Services and Messages to clients. Deleting one takes it off its
 * products.
 */
class ProductTagResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ProductTag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 29;

    protected static ?string $navigationLabel = 'Product tags';

    protected static ?string $modelLabel = 'product tag';

    protected static ?string $pluralModelLabel = 'Product tags';

    protected static ?string $slug = 'product-tags';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Labels such as New or SSD: badges clients see on the order pages, and filters for your lists and messages.');
    }

    public static function form(Schema $schema): Schema
    {
        $locales = Locales::available();

        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'sm' => max(1, min(3, count($locales)))])->schema(collect($locales)->map(fn (string $name, string $locale) => TextInput::make("names.{$locale}")
                ->label(Locales::multiple() ? __('Name (:language)', ['language' => $name]) : __('Name'))
                ->placeholder(__('e.g. New'))
                ->maxLength(30)
                ->required(Locales::isDefault($locale)))->values()->all()),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                ColorCircles::make()
                    ->default('gray')
                    ->columnSpanFull(),
                TextInput::make('sort')
                    ->label('Position')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
            ]),
            Toggle::make('visible')
                ->label('Shown to clients')
                ->default(true)
                ->helperText(__('A badge on the product\'s order card, in the compare table and on the public home page (the first :count of a product). Off: only for your own filters.', ['count' => Product::BADGES])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->state(fn (ProductTag $record) => $record->name())
                    ->badge()
                    ->color(fn (ProductTag $record) => $record->filamentColor()),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
                IconColumn::make('visible')
                    ->label('Shown to clients')
                    ->boolean(),
                TextColumn::make('sort')
                    ->label('Position')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make()->modalWidth('2xl'),
                DeleteAction::make()
                    ->modalDescription(__('It is taken off its products; the products stay.')),
            ])
            ->emptyStateHeading(__('No product tags yet'))
            ->emptyStateDescription(__('Add one such as New, Sale or SSD, then tick it on your products.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductTags::route('/'),
        ];
    }
}
