<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use App\Filament\Forms\DomainAvailabilityHint;
use App\Filament\Forms\NameserversInput;
use App\Filament\Forms\PackageSelect;
use App\Filament\Forms\ProductOptionFields;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Tld;
use App\Rules\ValidDomain;
use App\Support\Coupons;
use App\Support\Currencies;
use App\Support\DomainName;
use App\Support\Money;
use App\Support\ProductOptions;
use App\Support\Tax;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * New order: a client and the items they ordered. Each item becomes a pending
 * service; nothing is created on a panel or registrar until "Complete order".
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->schema([
                        Select::make('client_id')
                            ->label('Client')
                            ->options(fn () => Client::query()->orderBy('last_name')->get()->mapWithKeys(fn (Client $c) => [$c->id => $c->displayName()]))
                            ->searchable()
                            ->required()
                            ->default(fn () => request()->integer('client') ?: null)
                            ->live()
                            // Prices follow the client's currency.
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::repriceItems($get, $set)),
                    ]),

                self::itemsRepeater(),

                // Billing → Coupons: the current ones; PlaceOrder checks its limits for this client.
                Select::make('coupon_id')
                    ->label('Coupon')
                    ->options(fn () => Coupon::query()->current()->orderBy('code')->get()
                        ->mapWithKeys(fn (Coupon $coupon) => [$coupon->id => "{$coupon->code} ({$coupon->describeValue()})"]))
                    ->placeholder(__('None'))
                    ->searchable()
                    ->live()
                    ->helperText(fn (Get $get) => $get('coupon_id') && $get('client_id')
                        ? Coupons::problem(Coupon::find($get('coupon_id')), Client::find($get('client_id')), self::couponItems($get('items') ?? []))
                        : null)
                    ->visible(fn () => Coupon::query()->current()->exists()),

                Text::make(fn (Get $get) => __('First payment total: :amount', ['amount' => Tax::describe(
                    Tax::totals($get('client_id') ? Client::find($get('client_id')) : null, self::amounts($get('items') ?? [], $get('coupon_id') ? Coupon::find($get('coupon_id')) : null, Currencies::forClient($get('client_id') ? Client::find($get('client_id')) : null))),
                    Currencies::forClient($get('client_id') ? Client::find($get('client_id')) : null),
                )])),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The order's items (also a quote's, `$forQuote`: an EPP code is not asked yet, the client gives it later).
     */
    public static function itemsRepeater(bool $forQuote = false): Repeater
    {
        return Repeater::make('items')
            ->columnSpanFull()
            ->columns(3)
            // A quote may hold only one-off lines (its extras).
            ->minItems($forQuote ? 0 : 1)
            ->defaultItems(1)
            ->addActionLabel(__('Add item'))
            ->itemLabel(fn (array $state) => ($state['label'] ?? null) ?: ($state['domain'] ?? null))
            ->live()
            ->schema([
                // Type first: the product list shows only products of that type.
                Select::make('type')
                    ->options(ServiceType::class)
                    ->default(ServiceType::Domain)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::changeType($get, $set)),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (Get $get) => Product::query()->orderable()
                        ->when(self::type($get), fn ($query, ServiceType $type) => $query->where('type', $type))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('None (custom)'))
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, $state, $old) => self::fillFromProduct($get, $set, $state, $old)),
                // A domain is called by its name (PlaceOrder).
                TextInput::make('label')
                    ->required(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                    ->visible(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                    ->maxLength(255),
                // Hosting takes the order's domain only when the order has exactly one; otherwise it names its own.
                // Whether it is free, for a domain item (admins aren't rate limited).
                DomainAvailabilityHint::on(TextInput::make('domain'), fn (Get $get) => self::type($get) === ServiceType::Domain ? self::registration($get)?->value : null, limited: false)
                    ->required(fn (Get $get) => self::type($get) === ServiceType::Domain
                        || (self::type($get) === ServiceType::Hosting && self::orderDomain($get) === null))
                    ->maxLength(255)
                    ->rule(new ValidDomain)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        // A new domain starts with its TLD's preferred registrar; the admin can pick another.
                        $set('registrar_id', Tld::match($get('domain'), activeOnly: false)?->registrar_id);
                        self::fillDomain($get, $set);
                    })
                    ->helperText(fn (Get $get) => self::type($get) === ServiceType::Hosting && ($domain = self::orderDomain($get)) !== null
                        ? __('Empty = :domain, the domain of this order.', ['domain' => $domain])
                        : null),

                // Domain
                Select::make('registration_type')
                    ->label('Registration')
                    ->options(DomainRegistrationType::class)
                    ->default(DomainRegistrationType::Register)
                    ->required(fn (Get $get) => self::type($get) === ServiceType::Domain)
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillDomain($get, $set)),
                Select::make('years')
                    // Only the terms the TLD is priced for (Settings → Domain pricing; .gr: 2 years only).
                    ->options(fn (Get $get) => collect(self::termsFor($get))->mapWithKeys(fn (int $years) => [$years => Tld::termLabel($years)])->all())
                    ->default(1)
                    ->required(fn (Get $get) => self::type($get) === ServiceType::Domain)
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain)
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillDomain($get, $set)),
                Select::make('registrar_id')
                    ->label('Registrar')
                    ->options(fn () => Registrar::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('The TLD\'s preferred registrar'))
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain),
                TextInput::make('epp_code')
                    ->label('EPP code')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->maxLength(255)
                    ->required(fn (Get $get) => self::registration($get) === DomainRegistrationType::Transfer)
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain && self::registration($get) === DomainRegistrationType::Transfer),
                Toggle::make('auto_renew')
                    ->label('Auto renew')
                    ->default(fn () => (bool) config('gaiacp.domains.auto_renew', true))
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain),
                NameserversInput::make()
                    ->placeholder(__('Default from Settings'))
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Domain && self::registration($get) === DomainRegistrationType::Register),

                // Hosting
                Select::make('server_id')
                    ->label('Server')
                    // Each with its accounts against its maximum ("Server 1 · accounts: 33/120 (28%)")
                    ->options(fn () => Server::query()->where('active', true)->withAccountsCount()->orderBy('name')->get()
                        ->mapWithKeys(fn (Server $server) => [$server->id => $server->optionLabel()]))
                    ->required(fn (Get $get) => self::type($get) === ServiceType::Hosting)
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Hosting)
                    ->live(),
                PackageSelect::make(fn (Get $get) => $get('server_id'))
                    ->visible(fn (Get $get) => self::type($get) === ServiceType::Hosting),

                // The product's options (Settings → Product options); each pick changes the price.
                Group::make(fn (Get $get) => ProductOptionFields::make(
                    $get('product_id') ? Product::find($get('product_id')) : null,
                    fn (Get $get) => self::enum(BillingCycle::class, $get('billing_cycle')),
                    fn (Get $get) => self::currency($get),
                    fn (Get $get, $set) => self::fillPrice($get, $set),
                ))
                    ->columns(3)
                    ->columnSpanFull(),

                // Billing
                Select::make('billing_cycle')
                    // One time only for other services (paid once, then Completed)
                    ->options(fn (Get $get) => BillingCycle::optionsFor(self::type($get)))
                    ->enum(BillingCycle::class)
                    ->default(BillingCycle::Annually)
                    ->required()
                    // A domain's cycle is its term, set from the years field.
                    ->visible(fn (Get $get) => self::type($get) !== ServiceType::Domain)
                    ->dehydratedWhenHidden()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fillPrice($get, $set)),
                TextInput::make('price_cents')
                    ->label(fn (Get $get) => self::type($get) === ServiceType::Domain ? 'Renewal price' : 'Price per cycle')
                    ->prefix(fn (Get $get) => self::currency($get))
                    ->required()
                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null),
                TextInput::make('first_payment_cents')
                    ->label('First payment')
                    ->prefix(fn (Get $get) => self::currency($get))
                    ->rules(['nullable', 'regex:/^\d+([.,]\d{1,2})?$/'])
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Money::toCents($state) : null)
                    ->helperText(fn (Get $get) => self::type($get) === ServiceType::Domain
                        ? __('Registration or transfer price. Empty = the renewal price.')
                        : __('Price plus setup fee. Empty = the price.')),
            ]);
    }

    /**
     * Each item's first payment in cents, as entered so far, less what a coupon takes off.
     *
     * @param  array<int|string, array<string, mixed>>  $items
     * @return list<int>
     */
    public static function amounts(array $items, ?Coupon $coupon = null, ?string $currency = null): array
    {
        $items = self::couponItems($items);

        if ($coupon !== null && Coupons::problem($coupon, null, $items) === null) {
            $items = Coupons::apply($coupon, $items, $currency ?? Currencies::base())['items'];
        }

        return collect($items)->map(fn (array $item) => $item['first_payment_cents'] ?? $item['price_cents'])->all();
    }

    /**
     * The items as entered so far, prices in cents, as PlaceOrder gets them.
     *
     * @param  array<int|string, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function couponItems(array $items): array
    {
        $cents = fn ($amount) => filled($amount) && preg_match('/^\d+([.,]\d{1,2})?$/', (string) $amount) ? Money::toCents($amount) : null;

        return collect($items)->values()->map(fn (array $item) => [
            'type' => $item['type'] ?? null,
            'product_id' => $item['product_id'] ?? null,
            'domain' => $item['domain'] ?? null,
            'price_cents' => $cents($item['price_cents'] ?? null) ?? 0,
            'first_payment_cents' => $cents($item['first_payment_cents'] ?? null),
        ])->all();
    }

    /**
     * A new type drops the product and the prices it filled in (a custom label stays), then
     * prices a domain from its TLD again.
     */
    private static function changeType(Get $get, Set $set): void
    {
        $product = $get('product_id') ? Product::find($get('product_id')) : null;

        if ($product && $product->type !== self::type($get)) {
            $set('product_id', null);

            if ($get('label') === $product->name) {
                $set('label', null);
            }
        }

        $set('price_cents', null);
        $set('first_payment_cents', null);

        self::fillPrice($get, $set);
        self::fillDomain($get, $set);
    }

    /** Fills the item from the picked product; prices are always those of the new pick, never the last one's. */
    private static function fillFromProduct(Get $get, Set $set, mixed $productId, mixed $previousId = null): void
    {
        $product = $productId ? Product::find($productId) : null;
        $previous = $previousId ? Product::find($previousId) : null;

        $set('price_cents', null);
        $set('first_payment_cents', null);

        if (! $product) {
            $set('options', []);

            if ($previous && $get('label') === $previous->name) {
                $set('label', null);
            }

            self::fillDomain($get, $set);

            return;
        }

        $set('type', $product->type);
        $set('label', $product->name);
        $set('options', ProductOptions::defaults(ProductOptions::for($product)));
        $set('server_id', $product->pickServer()?->id);
        $set('package', $product->package);
        $set('billing_cycle', $product->defaultCycle());

        self::fillPrice($get, $set);
        self::fillDomain($get, $set);
    }

    /**
     * The product's price for the picked cycle plus its picked options; the setup fees go into the first payment. A
     * cycle the product has no price for empties both; without a product they are left as typed.
     */
    private static function fillPrice(callable $get, callable $set): void
    {
        $product = $get('product_id') ? Product::find($get('product_id')) : null;

        if (! $product || $product->type === ServiceType::Domain) {
            return;
        }

        $cycle = self::enum(BillingCycle::class, $get('billing_cycle'));
        $currency = self::currency($get);
        $price = $cycle ? $product->priceFor($cycle, $currency) : null;

        if ($price === null) {
            $set('price_cents', null);
            $set('first_payment_cents', null);

            return;
        }

        $picks = ProductOptions::select($product, (array) ($get('options') ?? []), $cycle, $currency);
        // While the product's sale runs its sale price is the first payment; renewals are at the price.
        $first = ($product->salePriceFor($cycle, $currency) ?? $price) + ProductOptions::total($picks)
            + $product->setupFeeFor($cycle, $currency) + ProductOptions::setupTotal($picks);
        $price += ProductOptions::total($picks);
        $set('price_cents', Money::toDecimal($price));
        $set('first_payment_cents', $first !== $price ? Money::toDecimal($first) : null);
    }

    /** Domain prices and term from the TLD's prices (Settings → Domain pricing, its sale price while it runs), when it is priced. */
    private static function fillDomain(callable $get, callable $set): void
    {
        if (self::type($get) !== ServiceType::Domain) {
            return;
        }

        $years = (int) ($get('years') ?: 1);
        $terms = self::termsFor($get);

        if (! in_array($years, $terms, true)) {
            $years = $terms[0];
            $set('years', $years);
        }

        $set('billing_cycle', BillingCycle::forYears($years));

        $tld = self::tld($get);

        if (! $tld) {
            return;
        }

        $currency = self::currency($get);
        $renew = $tld->priceCents('renew', $years, $currency);
        $first = match (self::registration($get)) {
            DomainRegistrationType::Register => $tld->orderPriceCents('register', $years, $currency),
            DomainRegistrationType::Transfer => $tld->orderPriceCents('transfer', $years, $currency),
            default => null,
        };

        if ($renew !== null) {
            $set('price_cents', Money::toDecimal($renew));
        }

        $set('first_payment_cents', $first === null ? null : Money::toDecimal($first));
    }

    /** The currency of the order's client, inside an item (Currencies::forClient()). */
    private static function currency(callable $get): string
    {
        $client = $get('../../client_id');

        return Currencies::forClient($client ? Client::find($client) : null);
    }

    /** Another client may be billed in another currency: each item's prices again, from its product or TLD. */
    public static function repriceItems(Get $get, Set $set): void
    {
        foreach (array_keys($get('items') ?? []) as $key) {
            $itemGet = fn (string $path) => str_starts_with($path, '../../') ? $get(substr($path, 6)) : $get("items.{$key}.{$path}");
            $itemSet = fn (string $path, mixed $value) => $set("items.{$key}.{$path}", $value);

            self::fillPrice($itemGet, $itemSet);
            self::fillDomain($itemGet, $itemSet);
        }
    }

    /** The TLD row for the item's domain. */
    private static function tld(callable $get): ?Tld
    {
        return Tld::match($get('domain'));
    }

    /**
     * The terms in years a domain can be ordered for: those its TLD has a price for (register or
     * transfer, else renew for a tracked one). Every term when the TLD is unknown or has no prices at all.
     *
     * @return list<int>
     */
    private static function termsFor(callable $get): array
    {
        $tld = self::tld($get);
        $kind = match (self::registration($get)) {
            DomainRegistrationType::Transfer => 'transfer',
            DomainRegistrationType::Register => 'register',
            default => 'renew',
        };

        return $tld?->terms($kind) ?: Tld::YEARS;
    }

    /** The order's domain a hosting item without one takes (PlaceOrder): only when the order has exactly one domain item. */
    private static function orderDomain(callable $get): ?string
    {
        $domains = collect($get('../../items') ?? [])
            ->filter(fn (array $item) => self::enum(ServiceType::class, $item['type'] ?? null) === ServiceType::Domain)
            ->map(fn (array $item) => DomainName::normalize($item['domain'] ?? null))
            ->filter();

        return $domains->count() === 1 ? $domains->first() : null;
    }

    private static function type(callable $get): ?ServiceType
    {
        return self::enum(ServiceType::class, $get('type'));
    }

    private static function registration(callable $get): ?DomainRegistrationType
    {
        return self::enum(DomainRegistrationType::class, $get('registration_type'));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $state): ?\BackedEnum
    {
        return $state instanceof $enum ? $state : ($state === null || $state === '' ? null : $enum::tryFrom($state));
    }
}
