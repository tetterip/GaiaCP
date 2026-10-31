<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Actions\ClientOrder;
use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Forms\MenuRowBlock;
use App\Models\ProductGroup;
use App\Support\ClientMenu;
use App\Support\Locales;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings → Client area → Menus: the client area's side menus (App\Support\ClientMenu) as a list to drag into order,
 * one for signed-in clients and one for visitors. Each built-in page and heading can be renamed, moved or taken out;
 * products, product groups, links and headings of your own can be added.
 */
class MenuSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Menus';

    protected static ?string $slug = 'menus';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('The side menus of the client area, for clients who are logged in and for visitors: drag the lines into order, rename or take out a page, and add products, product groups, links and headings of your own.');
    }

    public static function keys(): array
    {
        return [ClientMenu::settingKey(ClientMenu::CLIENT), ClientMenu::settingKey(ClientMenu::PUBLIC)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to default')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Both menus go back to the way they come, with every page in its usual place. The lines you added are removed.'))
                ->action(function () {
                    Settings::save(array_fill_keys(static::keys(), null));
                    $this->mount();
                    Notification::make()->success()->title(__('The menus are back to the way they come'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('menus')->tabs([
                    Tab::make(__('Logged-in clients'))->schema([
                        Text::make(__('Lines before the first heading stand alone at the top; the lines after a heading are in its section. A heading with nothing visible under it is left out. Pages that are switched off elsewhere stay hidden even here.')),
                        $this->menu(ClientMenu::CLIENT),
                    ]),
                    Tab::make(__('Visitors'))->schema([
                        Text::make(__('What visitors see before they log in, while Settings → Public area has something switched on.')),
                        $this->menu(ClientMenu::PUBLIC),
                    ]),
                ]),
            ]);
    }

    private function menu(string $menu): Builder
    {
        $pages = ClientMenu::pages($menu);
        $headings = ClientMenu::headings($menu);

        return Builder::make(ClientMenu::settingKey($menu).'.items')
            ->hiddenLabel()
            ->blocks([
                MenuRowBlock::make('page')
                    ->label(fn (?array $state) => $state === null ? __('Page') : ($this->rowName($state, $pages[$state['key'] ?? '']['name'] ?? __('Page'))))
                    ->icon(Heroicon::OutlinedDocument)
                    ->describe(fn (array $data) => $this->preview($data, $pages[$data['key'] ?? '']['icon'] ?? null, __('Page').' · '.($pages[$data['key'] ?? '']['name'] ?? '?')))
                    ->schema([
                        Select::make('key')
                            ->label('Page')
                            ->options(collect($pages)->map(fn (array $page) => $page['name']))
                            ->required()
                            ->native(false),
                        ...$this->looks(required: false),
                    ]),
                MenuRowBlock::make('heading')
                    ->label(fn (?array $state) => $state === null ? __('Heading') : mb_strtoupper($this->rowName($state, $headings[$state['key'] ?? ''] ?? __('Heading'))))
                    ->icon(Heroicon::OutlinedBars2)
                    ->describe(fn (array $data) => ['icon' => null, 'text' => filled($data['key'] ?? null) ? __('Heading').' · '.($headings[$data['key']] ?? '?') : __('Heading of your own')])
                    ->schema([
                        Hidden::make('key'),
                        $this->labels(required: fn (Get $get) => blank($get('key'))),
                    ]),
                MenuRowBlock::make('product')
                    ->label(fn (?array $state) => $state === null ? __('Product') : $this->rowName($state, ClientOrder::products()->firstWhere('id', (int) ($state['product'] ?? 0))?->localName() ?? __('Product')))
                    ->icon(Heroicon::OutlinedCube)
                    ->describe(fn (array $data) => $this->preview($data, null, __('Product').' · '.(ClientOrder::products()->firstWhere('id', (int) ($data['product'] ?? 0))?->localName() ?? '?')))
                    ->schema([
                        Select::make('product')
                            ->label('Product')
                            ->helperText(__('Opens its public page when it has one, else the order page with it picked.'))
                            ->options(fn () => ClientOrder::products()->mapWithKeys(fn ($product) => [$product->id => $product->localName()]))
                            ->required()
                            ->searchable(),
                        ...$this->looks(required: false),
                    ]),
                MenuRowBlock::make('category')
                    ->label(fn (?array $state) => $state === null ? __('Product group') : $this->rowName($state, ProductGroup::query()->find((int) ($state['category'] ?? 0))?->name() ?? __('Product group')))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->describe(fn (array $data) => $this->preview($data, null, __('Product group').' · '.(ProductGroup::query()->find((int) ($data['category'] ?? 0))?->name() ?? '?')))
                    ->schema([
                        Select::make('category')
                            ->label('Product group')
                            ->helperText(__('Opens its public page when it has one, else the order page on that group.'))
                            ->options(fn () => ProductGroup::query()->orderBy('sort')->get()->mapWithKeys(fn (ProductGroup $group) => [$group->id => $group->name()]))
                            ->required()
                            ->native(false),
                        ...$this->looks(required: false),
                    ]),
                MenuRowBlock::make('link')
                    ->label(fn (?array $state) => $state === null ? __('Link') : $this->rowName($state, __('Link')))
                    ->icon(Heroicon::OutlinedLink)
                    ->describe(fn (array $data) => $this->preview($data, 'heroicon-o-link', ($data['url'] ?? '').(! empty($data['new_tab']) ? ' · '.__('opens in a new tab') : '')))
                    ->schema([
                        TextInput::make('url')
                            ->label('Address')
                            ->placeholder('https://')
                            ->helperText(__('A full address (https://…) or a path on this site (/…).'))
                            ->required()
                            ->maxLength(500)
                            ->regex('#^(https?://\S+|/\S*)$#'),
                        ...$this->looks(required: true),
                        Toggle::make('new_tab')->label('Open in a new tab'),
                    ]),
            ])
            ->addActionLabel(__('Add to the menu'))
            ->blockPickerColumns(2)
            ->blockPreviews()
            // Room under Add to the menu, so its list opens clear of the floating Save bar on a phone
            ->extraAttributes(['style' => 'margin-bottom: 4rem'])
            // New lines go at the end and are dragged into place: a picker between every two lines would weigh the page down
            ->addBetweenAction(fn (Action $action) => $action->hidden())
            ->cloneable(false)
            ->blockNumbers(false);
    }

    /** The line's own name (one per language) and icon. */
    private function looks(bool $required): array
    {
        return [
            $this->labels(required: $required),
            Select::make('icon')
                ->label('Icon')
                ->placeholder(__('Its usual icon'))
                ->options(ClientMenu::iconOptions())
                ->prefixIcon(fn (Get $get) => ClientMenu::icon($get('icon')))
                ->live()
                ->native(false),
        ];
    }

    private function labels(bool|\Closure $required): Grid
    {
        $locales = Locales::available();

        return Grid::make(min(count($locales), 3))->schema(collect($locales)
            ->map(fn (string $name, string $locale) => TextInput::make("label.{$locale}")
                ->label(count($locales) > 1 ? __('Name in :language', ['language' => $name]) : __('Name'))
                ->placeholder(fn () => $required === true ? null : __('Its usual name'))
                ->required(fn (Get $get) => $locale === Locales::default() && ($required instanceof \Closure ? $required($get) : $required))
                ->maxLength(60))
            ->values()->all());
    }

    /** @return array{icon: ?string, text: string} a line's preview: its own icon (else $icon) and $text */
    private function preview(array $data, ?string $icon, string $text): array
    {
        return ['icon' => ClientMenu::icon($data['icon'] ?? null) ?? $icon, 'text' => $text];
    }

    private function rowName(array $state, string $usual): string
    {
        return ClientMenu::label($state) ?? $usual;
    }

    protected function forForm(array $values): array
    {
        foreach ([ClientMenu::CLIENT, ClientMenu::PUBLIC] as $menu) {
            $values[ClientMenu::settingKey($menu)] = ['items' => collect(ClientMenu::rows($menu))
                ->map(fn (array $row) => ['type' => $row['type'], 'data' => collect($row)->except('type')->all()])
                ->all()];
        }

        return $values;
    }

    protected function forStorage(array $values): array
    {
        foreach ([ClientMenu::CLIENT, ClientMenu::PUBLIC] as $menu) {
            $key = ClientMenu::settingKey($menu);
            $rows = collect((array) ($values[$key]['items'] ?? []))
                ->filter(fn ($block) => in_array($block['type'] ?? null, ClientMenu::TYPES, true))
                ->map(function (array $block) {
                    $data = (array) ($block['data'] ?? []);
                    $data['label'] = array_filter(array_map(fn ($text) => trim((string) $text), (array) ($data['label'] ?? [])), 'filled');

                    return array_filter(['type' => $block['type'], ...$data], fn ($value) => $value !== null && $value !== [] && $value !== '' && $value !== false);
                })
                ->values()
                ->all();

            // Every page there is now counts as seen: one left out stays out; one an update adds later shows up
            $values[$key] = $rows === ClientMenu::defaultRows($menu) ? null : ['items' => $rows, 'seen' => array_keys(ClientMenu::pages($menu))];
        }

        return $values;
    }
}
