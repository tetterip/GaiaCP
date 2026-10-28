<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Actions\CertificateLifecycle;
use App\Ai\Assistant;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Filament\Actions\AiTextActions;
use App\Filament\Forms\PackageSelect;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOptionGroup;
use App\Models\ProductTag;
use App\Support\Currencies;
use App\Support\Locales;
use App\Support\Money;
use App\Support\ProductIcons;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $isHosting = fn (Get $get) => in_array($get('type'), [ServiceType::Hosting, ServiceType::Hosting->value], true);

        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('Becomes the service label clients see in reminders.')),
                        Select::make('type')
                            ->options(ServiceType::class)
                            ->required()
                            ->live(),
                        Select::make('billing_cycle')
                            ->label('Default cycle')
                            ->options(fn (Get $get) => BillingCycle::optionsFor(self::type($get)))
                            ->enum(BillingCycle::class)
                            ->default(BillingCycle::Annually)
                            ->required()
                            ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                                $cycle = $value instanceof BillingCycle ? $value->value : $value;

                                if (! $get("prices.{$cycle}.enabled")) {
                                    $fail(__('Enable a price for the default cycle.'));
                                }
                            }])
                            ->helperText(__('Picked first on a new service or order.')),
                        Select::make('servers')
                            ->relationship('servers', 'name')
                            ->multiple()
                            ->preload()
                            ->live()
                            ->helperText(__('Servers that have this package. A new order goes to the least full active one; you can still change it.'))
                            ->visible($isHosting),
                        Select::make('package')
                            ->label('Certificate')
                            ->options(fn (?Product $record) => array_merge(
                                filled($record?->package) ? [$record->package => $record->package] : [],
                                app(CertificateLifecycle::class)->productOptions(),
                            ))
                            ->searchable()
                            ->required(fn () => app(CertificateLifecycle::class)->productOptions() !== [])
                            ->helperText(fn () => app(CertificateLifecycle::class)->productOptions() === []
                                ? __('Switch on an SSL provider under Settings → Plugins (GoGetSSL) and fill in its login.')
                                : __('The certificate ordered from the provider. Its validity (12 months, say) must fit the billing cycles you enable.'))
                            ->visible(fn (Get $get) => in_array($get('type'), [ServiceType::Ssl, ServiceType::Ssl->value], true)),
                        PackageSelect::make(fn (Get $get) => Arr::first((array) $get('servers')))
                            ->helperText(__('Package / service plan name on the panel.'))
                            ->visible($isHosting),
                        AiTextActions::on(Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(), write: [[
                                'name' => 'aiWrite',
                                'label' => __('Write from the product\'s details'),
                                'feature' => 'products',
                                'ask' => fn (Assistant $ai, string $draft, Get $get) => $ai->productDescription(self::details($get), Locales::default(), $draft),
                            ]]),
                        Toggle::make('addon')
                            ->label('Add-on')
                            ->live()
                            ->helperText(__('Sold only on top of a service (its page → Add-ons), on the same cycle and due date; never on its own in New order or the client area.')),
                        Select::make('addonFor')
                            ->label('Add-on for')
                            ->relationship('addonFor', 'name', fn (Builder $query, ?Product $record) => $query->where('addon', false)->when($record, fn (Builder $q) => $q->whereKeyNot($record->id)))
                            ->multiple()
                            ->preload()
                            ->placeholder(__('Any product'))
                            ->visible(fn (Get $get) => (bool) $get('addon'))
                            ->helperText(__('The products whose services can have it. Empty = any.')),
                        Select::make('optionGroups')
                            ->label('Product options')
                            ->relationship('optionGroups', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText(__('Offered with it on New order and the service form; set them up under Settings → Product options.')),
                        Toggle::make('hidden')
                            ->helperText(__('Not offered in New order; still offered on a single new service.')),
                        Toggle::make('retired')
                            ->helperText(__('Not offered anywhere any more. Services that already have it keep it.')),
                        Toggle::make('client_visible')
                            ->label('Show in client area')
                            ->default(true)
                            ->helperText(__('Off: clients can never order it, and its new services are hidden from them. Maintenance shows to clients read-only and is never offered in their Order page.')),
                        Hidden::make('currency')
                            ->default(fn () => Currencies::base()),
                    ]),

                Section::make(__('Order page'))
                    ->description(__('Where clients find it when they order (Settings → Product groups) and what its card and the compare table show.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->hidden(fn (Get $get) => (bool) $get('addon'))
                    ->schema([
                        Select::make('product_group_id')
                            ->label('Group')
                            ->options(fn () => ProductGroup::query()->ordered()->get()->mapWithKeys(fn (ProductGroup $group) => [$group->id => $group->name()])->all())
                            ->placeholder(__('Other'))
                            ->live(),
                        Select::make('icon')
                            ->options(ProductIcons::options())
                            ->allowHtml()
                            ->native(false)
                            ->placeholder(__('The group\'s icon')),
                        Toggle::make('popular')
                            ->label('Popular')
                            ->helperText(__('A "Popular" badge on its card and in the compare table.'))
                            ->columnSpanFull(),
                        Select::make('tags')
                            ->relationship('tags', 'id')
                            ->getOptionLabelFromRecordUsing(fn (ProductTag $tag) => $tag->name())
                            ->multiple()
                            ->preload()
                            ->helperText(__('Settings → Product tags. The ones shown to clients are badges after Popular; all of them filter your lists.'))
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->key('features')
                            ->columnSpanFull()
                            ->schema(fn (Get $get) => collect(ProductGroup::query()->find($get('product_group_id'))?->featureNames() ?? [])
                                ->map(fn (string $name, string $key) => TextInput::make("features.{$key}")
                                    ->label($name)
                                    ->maxLength(100)
                                    ->placeholder(__('Yes, No, 10 GB, Unlimited…')))
                                ->values()->all()),
                    ]),

                Section::make(__('Setup'))
                    ->description(__('When a new service of this product is set up: created on its panel, registered at the registrar, or just made active.'))
                    ->columnSpanFull()
                    ->schema([
                        Radio::make('auto_setup')
                            ->hiddenLabel()
                            ->options(AutoSetup::class)
                            ->default(AutoSetup::OnAccept)
                            ->required(),
                    ]),

                Section::make(__('Pricing'))
                    ->description(__('Price per cycle and a one-off setup fee, charged with the first payment. Enable the cycles you offer.'))
                    ->columnSpanFull()
                    ->schema(collect(BillingCycle::cases())->map(fn (BillingCycle $cycle) => Grid::make(3)
                        // One time only for other services
                        ->visible(fn (Get $get) => array_key_exists($cycle->value, BillingCycle::optionsFor(self::type($get))))
                        ->schema([
                            Toggle::make("prices.{$cycle->value}.enabled")
                                ->label($cycle->getLabel())
                                ->inline(false)
                                ->live(),
                            self::money("prices.{$cycle->value}.price_cents", __('Price'))
                                ->required(fn (Get $get) => (bool) $get("prices.{$cycle->value}.enabled")),
                            self::money("prices.{$cycle->value}.setup_cents", __('Setup fee'))
                                ->placeholder('0.00'),
                        ]))->all()),

                Section::make(__('Prices in other currencies'))
                    ->description(__('For clients billed in another currency. Empty = the price above at that currency\'s rate; Update product prices on Settings → Currencies sets them all at today\'s rates.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn () => Currencies::multiple())
                    ->schema(fn () => collect(Currencies::others())->map(fn (string $code) => Fieldset::make($code)
                        ->columns(1)
                        ->schema(collect(BillingCycle::cases())->map(fn (BillingCycle $cycle) => Grid::make(3)
                            ->visible(fn (Get $get) => (bool) $get("prices.{$cycle->value}.enabled"))
                            ->schema([
                                Text::make($cycle->getLabel()),
                                self::money("currency_prices.{$code}.{$cycle->value}.price_cents", __('Price'), $code)
                                    ->placeholder(fn (Get $get) => self::converted($get("prices.{$cycle->value}.price_cents"), $code)),
                                self::money("currency_prices.{$code}.{$cycle->value}.setup_cents", __('Setup fee'), $code)
                                    ->placeholder(fn (Get $get) => self::converted($get("prices.{$cycle->value}.setup_cents"), $code) ?? '0.00'),
                            ]))->all()))->all()),
            ]);
    }

    /**
     * What the AI assistant writes a description from: the details typed so far, in English (prices left out, since
     * they change).
     *
     * @return array<string, string>
     */
    private static function details(Get $get): array
    {
        $group = ProductGroup::query()->find($get('product_group_id'));
        $features = collect($group?->featureNames() ?? [])
            ->map(fn (string $name, string $key) => filled($value = $get("features.{$key}")) ? "{$name}: {$value}" : null)
            ->filter()->implode('; ');

        return [
            'Name' => (string) $get('name'),
            'Type' => Assistant::typeName(self::type($get)),
            'Package on the server' => (string) $get('package'),
            'Group on the order page' => (string) $group?->name(Locales::default()),
            'Compare table' => $features,
            'Options clients can pick' => ProductOptionGroup::query()->whereKey((array) $get('optionGroups'))->pluck('name')->implode(', '),
            'Sold only as an add-on to another service' => $get('addon') ? 'yes' : '',
        ];
    }

    private static function type(Get $get): ?ServiceType
    {
        $type = $get('type');

        return $type instanceof ServiceType ? $type : ServiceType::tryFrom((string) $type);
    }

    /** A base amount as typed, at $code's rate, for the placeholder of a price not set in that currency. */
    private static function converted(mixed $base, string $code): ?string
    {
        try {
            return filled($base) ? Money::toDecimal(Currencies::fromBase(Money::toCents($base), $code)) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function money(string $name, string $label, ?string $currency = null): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix($currency ?? Currencies::base())
            ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toDecimal((int) $state))
            ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null);
    }
}
