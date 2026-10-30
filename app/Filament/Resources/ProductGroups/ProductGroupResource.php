<?php

namespace App\Filament\Resources\ProductGroups;

use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Client\Pages\ProductGroupPage;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroups;
use App\Models\ProductGroup;
use App\Support\Locales;
use App\Support\ProductIcons;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings → Product groups: how the order page, the public home page and the public side menu group the products
 * (ProductGroup), each with its link, icon and the features its compare table lists. Deleting one keeps its
 * products, under "Other".
 */
class ProductGroupResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ProductGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 29;

    protected static ?string $navigationLabel = 'Product groups';

    protected static ?string $modelLabel = 'product group';

    protected static ?string $pluralModelLabel = 'Product groups';

    protected static ?string $slug = 'product-groups';

    protected static bool $isGloballySearchable = false;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('How the order page groups your products (Shared hosting, VPS…), each with an icon, its own link and a compare table.');
    }

    public static function form(Schema $schema): Schema
    {
        $locales = Locales::available();

        return $schema->columns(1)->components([
            Tabs::make('texts')
                ->contained(false)
                ->tabs(collect($locales)->map(fn (string $name, string $locale) => Tab::make($name)
                    ->key("texts-{$locale}")
                    ->schema([
                        TextInput::make("names.{$locale}")
                            ->label('Name')
                            ->placeholder(__('e.g. Shared hosting'))
                            ->maxLength(100)
                            ->required(Locales::isDefault($locale)),
                        AiTextActions::forLocale(Textarea::make("descriptions.{$locale}")
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(500), $locale, fn (string $other) => "descriptions.{$other}"),
                    ]))->values()->all()),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('slug')
                    ->label('Link')
                    ->prefix(fn () => rtrim(route('filament.client.cart'), '/').'/')
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                        if (in_array(Str::slug((string) $value), ProductGroup::reservedSlugs(), true)) {
                            $fail(__('This link is taken by another page of the client area.'));
                        }
                    })
                    ->helperText(__('Empty: made from the name. Also opens the Order page on this group for clients, and is the address of its public page.')),
                Select::make('icon')
                    ->options(ProductIcons::options())
                    ->allowHtml()
                    ->native(false),
                TextInput::make('sort')
                    ->label('Position')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('hidden')
                    ->helperText(__('Left out of the order page, home page and menu; its link still opens it.'))
                    ->inline(false),
            ]),
            Section::make(__('Public page'))
                ->description(fn () => __('A page of its own at :url for visitors and search engines: its text, its products with their prices, the compare table and the questions below.', ['url' => rtrim(url('/'), '/').'/…']))
                ->compact()
                ->schema([
                    Toggle::make('page')
                        ->label('Public page'),
                    Tabs::make('page-texts')
                        ->contained(false)
                        ->visible(fn (Get $get) => (bool) $get('page'))
                        ->tabs(collect($locales)->map(fn (string $name, string $locale) => Tab::make($name)
                            ->key("page-texts-{$locale}")
                            ->schema([
                                TextInput::make("page_titles.{$locale}")
                                    ->label('Page title')
                                    ->placeholder(__('e.g. Fast web hosting with daily backups'))
                                    ->maxLength(100)
                                    ->helperText(__('The heading and the title in search results. Empty: the name.')),
                                Textarea::make("page_descriptions.{$locale}")
                                    ->label('Search result description')
                                    ->rows(2)
                                    ->maxLength(300)
                                    ->helperText(__('One or two sentences shown under the title in search results and link previews. Empty: the description.')),
                                AiTextActions::forLocale(Textarea::make("page_texts.{$locale}")
                                    ->label('Page text')
                                    ->rows(8)
                                    ->maxLength(10000)
                                    ->helperText(__('Markdown. What this kind of service is for, what it includes and who it suits, in your own words: search engines prefer pages with text of their own.')), $locale, fn (string $other) => "page_texts.{$other}"),
                            ]))->values()->all()),
                    Repeater::make('faq')
                        ->label('Questions')
                        ->visible(fn (Get $get) => (bool) $get('page'))
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => max(1, min(2, count($locales)))])->schema(collect($locales)->flatMap(fn (string $name, string $locale) => [
                                TextInput::make("questions.{$locale}")
                                    ->label(Locales::multiple() ? __('Question (:language)', ['language' => $name]) : __('Question'))
                                    ->maxLength(200)
                                    ->required(Locales::isDefault($locale)),
                                Textarea::make("answers.{$locale}")
                                    ->label(Locales::multiple() ? __('Answer (:language)', ['language' => $name]) : __('Answer'))
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->required(Locales::isDefault($locale)),
                            ])->values()->all()),
                        ])
                        ->addActionLabel(__('Add question'))
                        ->reorderable()
                        ->collapsible(false)
                        ->defaultItems(0),
                ]),
            Repeater::make('features')
                ->label('Compare table')
                ->helperText(__('The rows of the table that compares its products, such as Disk space or Email accounts. Each product fills in its values on its page (Order page section).'))
                ->schema([
                    Hidden::make('key'),
                    Grid::make(['default' => 1, 'sm' => max(1, min(3, count($locales)))])->schema(collect($locales)->map(fn (string $name, string $locale) => TextInput::make("names.{$locale}")
                        ->label(Locales::multiple() ? __('Name (:language)', ['language' => $name]) : __('Name'))
                        ->maxLength(100)
                        ->required(Locales::isDefault($locale)))->values()->all()),
                ])
                ->addActionLabel(__('Add row'))
                ->reorderable()
                ->collapsible(false)
                ->defaultItems(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->state(fn (ProductGroup $record) => new HtmlString('<span style="display:inline-flex;align-items:center;gap:.5rem">'.ProductIcons::svg($record->icon).e($record->name()).'</span>')),
                TextColumn::make('slug')
                    ->label('Link')
                    ->color('gray'),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
                IconColumn::make('hidden')
                    ->boolean(),
                IconColumn::make('page')
                    ->label('Public page')
                    ->boolean()
                    ->url(fn (ProductGroup $record) => ProductGroupPage::urlFor($record), shouldOpenInNewTab: true),
                TextColumn::make('sort')
                    ->label('Position')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make()->modalWidth('3xl'),
                DeleteAction::make()
                    ->modalDescription(__('Its products stay, under Other.')),
            ])
            ->emptyStateHeading(__('No product groups yet'))
            ->emptyStateDescription(__('Without groups, the order page lists every product together.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductGroups::route('/'),
        ];
    }
}
