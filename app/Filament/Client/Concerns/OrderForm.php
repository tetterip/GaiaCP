<?php

namespace App\Filament\Client\Concerns;

use App\Actions\ClientAddons;
use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Filament\Forms\DomainAvailabilityHint;
use App\Filament\Forms\ProductOptionFields;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Tld;
use App\Rules\ValidDomain;
use App\Support\Currencies;
use App\Support\DomainSsl;
use App\Support\Money;
use App\Support\OrderCatalogue;
use App\Support\ProductIcons;
use App\Support\ProductOptions;
use App\Support\Tax;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The catalogue and domain parts of an order form, shared by the client area's Order page and the public order page
 * (PublicOrder, before login). Prices show in orderClient()'s currency (the base one for a visitor); ClientOrder
 * prices the order again when it is placed.
 */
trait OrderForm
{
    /** The client ordering, null for a visitor who has not logged in yet. */
    abstract protected function orderClient(): ?Client;

    /**
     * The order's sections with the summary of the choice and the submit button at their end, beside the cart card
     * (its lines and next steps, then its buttons), which stays in view on wide screens; one column on phones.
     *
     * @param  list<Component>  $summary
     * @param  list<Component>  $cart  what the cart card shows
     * @param  list<Action>  $cartActions  buttons at the end of the cart card
     */
    protected function orderLayout(array $summary, Action $submit, array $cart = [], array $cartActions = []): Grid
    {
        return Grid::make(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make([
                    ...$this->orderComponents(),
                    Section::make()->schema([...$summary, Actions::make([$submit])->fullWidth()]),
                ])->columnSpan(['lg' => 2]),
                Section::make()
                    ->schema([...$cart, ...($cartActions !== [] ? [Actions::make($cartActions)->key('cartActions')->fullWidth()] : [])])
                    ->extraAttributes(['class' => 'gcp-order-summary'])
                    ->columnSpan(1),
            ]);
    }

    /** @return list<Section> */
    protected function orderComponents(): array
    {
        return [
            Section::make(__('What would you like to order?'))
                ->schema([
                    // First the group (Settings → Product groups), while there is more than one
                    Radio::make('group')
                        ->hiddenLabel()
                        ->extraAttributes(['class' => 'gcp-product-cards gcp-group-cards'])
                        ->options(fn (Get $get) => $this->groups($get)->map(fn (array $group) => self::cardLabel($group['icon'], $group['name']))->all())
                        ->descriptions(fn (Get $get) => $this->groups($get)->map(fn (array $group) => $this->groupDescription($group))->all())
                        ->required(fn (Get $get) => $this->hasGroupStep($get) && blank($get('product')))
                        ->visible(fn (Get $get) => $this->hasGroupStep($get))
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $set('product', $get('group') === OrderCatalogue::DOMAINS ? ClientOrder::DOMAIN_ONLY : null);
                            $set('billing_cycle', null);
                            $set('options', []);
                            $set('addons', []);
                            if ($get('group') === OrderCatalogue::DOMAINS) {
                                $set('domain_option', ClientOrder::REGISTER);
                            }
                            $this->keepTermValid($get, $set);
                        }),
                    Radio::make('product')
                        ->hiddenLabel()
                        // Shown as a grid of cards (filament.themes.client)
                        ->extraAttributes(['class' => 'gcp-product-cards'])
                        ->options(fn (Get $get) => $this->catalogue($get)->map(fn (array $entry) => $entry['name'])->all())
                        ->descriptions(fn (Get $get) => $this->catalogue($get)->map(fn (array $entry) => $entry['description'])->all())
                        ->required()
                        ->visible(fn (Get $get) => ! $this->hasGroupStep($get) || (filled($this->groupKey($get)) && $this->groupKey($get) !== OrderCatalogue::DOMAINS))
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $set('billing_cycle', $this->product($get)?->defaultCycle()?->value);
                            $set('options', ProductOptions::defaults(ProductOptions::for($this->product($get), hiddenToo: false)));
                            $set('addons', []);
                            $this->keepTermValid($get, $set);
                        }),
                    // The group's compare table (its features, a column for each product)
                    View::make('filament.client.compare-products')
                        // Its Choose buttons set the product field as its cards do (statePath: the form's "data.product")
                        ->viewData(fn (Get $get, View $component) => ['compare' => $this->compare($get), 'chosen' => (string) $get('product'), 'field' => $component->getContainer()->getStatePath().'.product'])
                        ->visible(fn (Get $get) => $this->compare($get) !== null),
                    Select::make('billing_cycle')
                        ->label('Billing cycle')
                        ->options(fn (Get $get) => collect($this->product($get)?->cycles() ?? [])->mapWithKeys(fn (BillingCycle $cycle) => [$cycle->value => $this->cycleOptionLabel($this->product($get), $cycle)])->all())
                        ->required(fn (Get $get) => $this->product($get) !== null)
                        ->visible(fn (Get $get) => $this->product($get) !== null)
                        ->selectablePlaceholder(false)
                        ->live()
                        // Add-ons not priced for the new cycle are no longer ticked.
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('addons', array_values(array_intersect(
                            array_map('intval', (array) $get('addons')),
                            $this->addons($get)->pluck('id')->all(),
                        )))),
                    // The product's options (Settings → Product options), each with what it adds for the cycle.
                    Grid::make(['default' => 1, 'md' => 2])
                        ->key('productOptions')
                        ->schema(fn (Get $get) => ProductOptionFields::make(
                            $this->product($get),
                            fn (Get $get) => BillingCycle::tryFrom((string) $get('billing_cycle')),
                            fn () => $this->currency(),
                            fn () => null,
                            client: true,
                        )),
                    // Add-ons offered with the product, on its cycle (they renew with it).
                    CheckboxList::make('addons')
                        ->label('Add-ons')
                        ->options(fn (Get $get) => $this->addons($get)->mapWithKeys(fn (Product $addon) => [$addon->id => $addon->name])->all())
                        ->descriptions(fn (Get $get) => $this->addons($get)->mapWithKeys(fn (Product $addon) => [$addon->id => self::productDescription($addon, [$this->addonPrice($addon, $get)], first: true)])->all())
                        ->helperText(__('They renew together with it.'))
                        ->visible(fn (Get $get) => $this->addons($get)->isNotEmpty())
                        ->live(),
                ]),

            Section::make(__('Domain'))
                ->visible(fn (Get $get) => $this->needsDomain($get))
                ->schema([
                    Radio::make('domain_option')
                        ->hiddenLabel()
                        ->options(fn (Get $get) => $this->isDomainOnly($get)
                            ? [ClientOrder::REGISTER => __('Register a new domain'), ClientOrder::TRANSFER => __('Transfer a domain I have elsewhere')]
                            : [ClientOrder::OWN => __('I already have this domain (I will point it here)'), ClientOrder::REGISTER => __('Register a new domain with this order'), ClientOrder::TRANSFER => __('Transfer my domain here with this order')])
                        ->required(fn (Get $get) => ! $this->isCertificate($get))
                        ->hidden(fn (Get $get) => $this->isCertificate($get))
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->keepTermValid($get, $set)),
                    DomainAvailabilityHint::on(TextInput::make('domain'), fn (Get $get) => $this->registersDomain($get) ? $this->option($get) : null)
                        ->label('Domain name')
                        ->placeholder('example.gr')
                        ->helperText(fn (Get $get) => $this->isCertificate($get) ? __('The domain the certificate is for. A wildcard certificate covers its subdomains too.') : null)
                        ->required()
                        ->maxLength(255)
                        ->rule(new ValidDomain)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->keepTermValid($get, $set)),
                    Select::make('years')
                        ->label('Term')
                        ->options(fn (Get $get) => collect($this->terms($get) ?: Tld::YEARS)->mapWithKeys(fn (int $years) => [$years => $this->termLabel($get, $years)])->all())
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get) => $this->registersDomain($get))
                        ->live(),
                    TextInput::make('epp_code')
                        ->label('EPP code')
                        ->helperText(__('The transfer code from your current provider.'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->maxLength(255)
                        ->required(fn (Get $get) => $this->option($get) === ClientOrder::TRANSFER)
                        ->visible(fn (Get $get) => $this->option($get) === ClientOrder::TRANSFER),
                    Checkbox::make('ssl')
                        ->label(fn () => __('Add :name for this domain', ['name' => DomainSsl::product()?->name]))
                        ->helperText(fn () => $this->sslPrice())
                        ->visible(fn (Get $get) => $this->isDomainOnly($get) && DomainSsl::product() !== null)
                        ->live(),
                    Text::make(__('We check that the domain is available and register or transfer it when we set up your order.'))
                        ->visible(fn (Get $get) => $this->registersDomain($get)),
                    Actions::make([
                        Action::make('noDomain')
                            ->label(__('I don\'t want a domain: choose something else'))
                            ->link()
                            ->icon(Heroicon::OutlinedArrowUturnLeft)
                            ->action(function (Set $set) {
                                $set('product', null);
                                $set('domain_option', ClientOrder::OWN);
                                $set('domain', null);
                                $set('epp_code', null);
                                $set('ssl', false);
                                $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');
                            }),
                    ])->key('noDomainActions')->visible(fn (Get $get) => $this->isDomainOnly($get)),
                ]),
        ];
    }

    /**
     * The product cards: the chosen group's products, or every product and "A domain name only" while there is no
     * group to choose.
     *
     * @return Collection<string, array{name: string|HtmlString, description: string|HtmlString}> product id (or "domain") => name and price
     */
    private function catalogue(Get $get): Collection
    {
        $groups = $this->groups($get);
        $products = $this->hasGroupStep($get)
            ? ($groups[(string) $this->groupKey($get)]['products'] ?? collect())
            : $groups->flatMap(fn (array $group) => $group['products']);

        $entries = $products->mapWithKeys(fn (Product $product) => [(string) $product->id => [
            'name' => self::cardLabel($product->icon ?? $product->group?->icon, $product->name, $product),
            'description' => self::productDescription($product, $this->fromPrice($product)),
        ]]);

        if (! $this->hasGroupStep($get) && $groups->has(OrderCatalogue::DOMAINS)) {
            $entries[ClientOrder::DOMAIN_ONLY] = ['name' => self::cardLabel('globe', __('A domain name only')), 'description' => __('Register a new domain or transfer one you have elsewhere.')];
        }

        return $entries;
    }

    /** @return Collection<string, array{key: string, name: string, description: ?string, icon: ?string, group: ?ProductGroup, products: Collection<int, Product>}> */
    private function groups(Get $get): Collection
    {
        return OrderCatalogue::groups($this->groupKey($get));
    }

    /** The chosen group, else the one of the chosen product (a link or a form filled in with only the product). */
    private function groupKey(Get $get): ?string
    {
        return filled($get('group')) ? (string) $get('group') : OrderCatalogue::keyFor($get('product'));
    }

    /**
     * Whether the client first picks a group: some product is in one (Settings → Product groups) and there is more than
     * one to pick from, domain names included. Without groups every product and "A domain name only" show together.
     */
    private function hasGroupStep(Get $get): bool
    {
        $groups = $this->groups($get);

        return $groups->count() > 1 && $groups->contains(fn (array $group) => $group['group'] !== null);
    }

    /** A card's name with its icon before it, and a product's badges after it (Popular, its visible tags). */
    private static function cardLabel(?string $icon, string $name, ?Product $product = null): HtmlString
    {
        $badge = $product === null ? '' : view('filament.client.product-badges', ['popular' => $product->popular, 'badges' => $product->badges()])->render();

        return new HtmlString('<span class="gcp-card-name">'.ProductIcons::svg($icon).'<span>'.e($name).'</span>'.$badge.'</span>');
    }

    /** @param  array{key: string, description: ?string, products: Collection<int, Product>}  $group */
    private function groupDescription(array $group): HtmlString
    {
        $from = $group['products']->map(fn (Product $product) => $this->lowestMonthly($product))->filter()->sortBy('cents')->first();
        $lines = array_filter([
            filled($group['description']) ? e($group['description']) : null,
            $from !== null ? '<strong>'.e(__('From :price', ['price' => $from['label']])).'</strong>' : null,
        ]);

        return new HtmlString(implode('<br>', $lines));
    }

    /** @return array{cents: float, label: string}|null the product's cheapest cycle, for "From €5.00 / month" on its group's card */
    private function lowestMonthly(Product $product): ?array
    {
        $cycle = collect($product->cycles())->sortBy(fn (BillingCycle $c) => $product->priceFor($c, $this->currency()) / max(1, $c->months()))->first();

        if ($cycle === null) {
            return null;
        }

        $cents = $product->priceFor($cycle, $this->currency()) / max(1, $cycle->months());

        return ['cents' => $cents, 'label' => $cycle->months() >= 1 ? $this->monthlyPrice($product, $cycle) : $this->cycleLabel($product, $cycle)];
    }

    /**
     * The compare table of the chosen group, when it has features: a row for each feature with each product's value,
     * then a row for each billing cycle any of them has with its price for it (null = not offered on that cycle).
     *
     * @return array{features: array<string, string>, cycles: array<string, string>, products: list<array{id: string, name: string, price: string, popular: bool, badges: list<array{name: string, color: string}>, values: array<string, ?string>, prices: array<string, ?array{price: string, note: ?string}>}>}|null
     */
    private function compare(Get $get): ?array
    {
        $group = $this->groups($get)[(string) $this->groupKey($get)] ?? ($this->hasGroupStep($get) ? null : $this->groups($get)->first());
        $features = $group === null ? [] : ($group['group']?->featureNames() ?? []);

        if ($features === [] || $group['products']->isEmpty()) {
            return null;
        }

        $cycles = collect(BillingCycle::cases())
            ->filter(fn (BillingCycle $cycle) => $group['products']->contains(fn (Product $product) => in_array($cycle, $product->cycles(), true)));

        return [
            'features' => $features,
            'cycles' => $cycles->mapWithKeys(fn (BillingCycle $cycle) => [$cycle->value => $cycle->getLabel()])->all(),
            'products' => $group['products']->map(fn (Product $product) => [
                'id' => (string) $product->id,
                'name' => $product->name,
                'price' => $this->fromPrice($product)[0] ?? '',
                'popular' => $product->popular,
                'badges' => $product->badges(),
                'values' => collect($features)->map(fn ($name, string $key) => $product->feature($key))->all(),
                'prices' => $cycles->mapWithKeys(fn (BillingCycle $cycle) => [$cycle->value => in_array($cycle, $product->cycles(), true) ? $this->comparePrice($product, $cycle) : null])->all(),
            ])->values()->all(),
        ];
    }

    /**
     * A product's price for one cycle in the compare table, with a month's share under it (Settings → Client area → Show
     * prices per month) and its setup fee, when it has one.
     *
     * @return array{price: string, note: ?string}
     */
    private function comparePrice(Product $product, BillingCycle $cycle): array
    {
        $setup = $product->setupFeeFor($cycle, $this->currency());
        $notes = array_filter([
            config('gaiacp.client_area.monthly_prices') && $cycle->months() > 1 ? $this->monthlyPrice($product, $cycle) : null,
            $setup > 0 ? __('+ :setup setup', ['setup' => Money::format($setup, $this->currency())]) : null,
        ]);

        return [
            'price' => Money::format($product->priceFor($cycle, $this->currency()), $this->currency()),
            'note' => $notes === [] ? null : implode(' · ', $notes),
        ];
    }

    /**
     * A product's description on its card, one line for each of its lines (typed in the textarea, or <br> and paragraphs
     * in one imported from WHMCS, whose entities such as &amp; are decoded), with the price on a line of its own.
     */
    /** @param  list<string>  $price  the price's lines */
    private static function productDescription(Product $product, array $price, bool $first = false): HtmlString
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>|</(p|div|li|h[1-6])>~i', "\n", (string) $product->description)), ENT_QUOTES | ENT_HTML5);
        $lines = collect(preg_split('/\R/u', $text) ?: [])->map(fn (string $line) => trim($line))->filter()->take(10)
            ->map(fn (string $line) => e(Str::limit($line, 80)));
        // The price's first line in bold, so it stands out from the features
        $price = array_values(array_map(fn (string $line) => e($line), array_filter($price, 'filled')));
        $price = $price === [] ? [] : ['<strong>'.$price[0].'</strong>', ...array_slice($price, 1)];
        $lines = $first ? collect($price)->concat($lines) : $lines->concat($price);

        return new HtmlString($lines->implode('<br>'));
    }

    /**
     * The card's price: the cycle that costs least a month, as its whole price or (Settings → Client area → Show prices
     * per month) as a month's share with what is billed each cycle under it.
     *
     * @return list<string>
     */
    private function fromPrice(Product $product): array
    {
        $cycle = collect($product->cycles())->sortBy(fn (BillingCycle $c) => $product->priceFor($c, $this->currency()) / max(1, $c->months()))->first();

        if (! config('gaiacp.client_area.monthly_prices') || $cycle->months() <= 1) {
            return [__('From :price', ['price' => $this->cycleLabel($product, $cycle)])];
        }

        return [__('From :price', ['price' => $this->monthlyPrice($product, $cycle)]), $this->billedLine($product, $cycle)];
    }

    /** A billing cycle in the Order form's list: its whole price, or a month's share with what is billed (see fromPrice()). */
    private function cycleOptionLabel(Product $product, BillingCycle $cycle): string
    {
        if (! config('gaiacp.client_area.monthly_prices') || $cycle->months() <= 1) {
            return $this->cycleLabel($product, $cycle);
        }

        return __(':price (:billed)', ['price' => $this->monthlyPrice($product, $cycle), 'billed' => Str::lcfirst($this->billedLine($product, $cycle))]);
    }

    private function monthlyPrice(Product $product, BillingCycle $cycle): string
    {
        return __(':price / month', ['price' => Money::format((int) round($product->priceFor($cycle, $this->currency()) / $cycle->months()), $this->currency())]);
    }

    /** "Billed €400.00 every 2 years", with the setup fee when there is one. */
    private function billedLine(Product $product, BillingCycle $cycle): string
    {
        $price = Money::format($product->priceFor($cycle, $this->currency()), $this->currency());
        $months = $cycle->months();
        $line = match (true) {
            $months === 12 => __('Billed :price every year', ['price' => $price]),
            $months % 12 === 0 => __('Billed :price every :years years', ['price' => $price, 'years' => intdiv($months, 12)]),
            default => __('Billed :price every :months months', ['price' => $price, 'months' => $months]),
        };
        $setup = $product->setupFeeFor($cycle, $this->currency());

        return $setup > 0 ? __(':price + :setup setup', ['price' => $line, 'setup' => Money::format($setup, $this->currency())]) : $line;
    }

    private function cycleLabel(Product $product, BillingCycle $cycle): string
    {
        $setup = $product->setupFeeFor($cycle, $this->currency());
        $label = Money::format($product->priceFor($cycle, $this->currency()), $this->currency()).' / '.$cycle->getLabel();

        return $setup > 0 ? __(':price + :setup setup', ['price' => $label, 'setup' => Money::format($setup, $this->currency())]) : $label;
    }

    /** The client's currency (the base one for a visitor): prices show and are charged in it. */
    private function currency(): string
    {
        return Currencies::forClient($this->orderClient());
    }

    private function product(Get $get): ?Product
    {
        $id = (string) $get('product');

        return $id === '' || $id === ClientOrder::DOMAIN_ONLY ? null : ClientOrder::products()->firstWhere('id', (int) $id);
    }

    /** @return Collection<int, Product> the add-ons offered with the chosen product and cycle */
    private function addons(Get $get): Collection
    {
        $product = $this->product($get);
        $cycle = BillingCycle::tryFrom((string) $get('billing_cycle')) ?? $product?->defaultCycle();

        return $product && $cycle ? ClientAddons::offered($product, $cycle, $this->currency()) : collect();
    }

    private function addonPrice(Product $addon, Get $get): string
    {
        $cycle = BillingCycle::tryFrom((string) $get('billing_cycle')) ?? $this->product($get)?->defaultCycle();

        return $cycle && $addon->priceFor($cycle, $this->currency()) !== null ? $this->cycleLabel($addon, $cycle) : '';
    }

    /** "€8.00 with the domain, then €10.00 (Annually)" for the SSL certificate offered with domains. */
    private function sslPrice(): ?string
    {
        $item = DomainSsl::item('example.com', $this->currency());

        return $item === null ? null : self::sslPriceText($item, $this->currency());
    }

    /** @param  array<string, mixed>  $item  a DomainSsl::item() */
    public static function sslPriceText(array $item, string $currency): string
    {
        $first = $item['first_payment_cents'] ?? $item['price_cents'];

        return $first === $item['price_cents']
            ? __(':price (:cycle)', ['price' => Money::format($item['price_cents'], $currency), 'cycle' => $item['billing_cycle']->getLabel()])
            : __(':first with the domain, then :price (:cycle)', ['first' => Money::format($first, $currency), 'price' => Money::format($item['price_cents'], $currency), 'cycle' => $item['billing_cycle']->getLabel()]);
    }

    private function isDomainOnly(Get $get): bool
    {
        return (string) $get('product') === ClientOrder::DOMAIN_ONLY;
    }

    private function needsDomain(Get $get): bool
    {
        return $this->isDomainOnly($get) || in_array($this->product($get)?->type, [ServiceType::Hosting, ServiceType::Ssl], true);
    }

    /** An SSL certificate is for a domain the client has: no registering or transferring with it. */
    private function isCertificate(Get $get): bool
    {
        return $this->product($get)?->type === ServiceType::Ssl;
    }

    private function option(Get $get): ?string
    {
        $option = $get('domain_option');

        // "I already have it" is not a choice for a domain on its own.
        return $this->isDomainOnly($get) && ($option === ClientOrder::OWN || $option === null) ? ClientOrder::REGISTER : $option;
    }

    private function registersDomain(Get $get): bool
    {
        return $this->needsDomain($get) && ! $this->isCertificate($get) && in_array($this->option($get), [ClientOrder::REGISTER, ClientOrder::TRANSFER], true);
    }

    /** A term the domain isn't offered for (.gr transfers only for 2 years, say) moves to the first one it is. */
    private function keepTermValid(Get $get, Set $set): void
    {
        $terms = $this->terms($get);

        if ($terms !== [] && ! in_array((int) $get('years'), $terms, true)) {
            $set('years', $terms[0]);
        }
    }

    /** @return list<int> */
    private function terms(Get $get): array
    {
        return ClientOrder::terms($get('domain'), (string) $this->option($get));
    }

    private function termLabel(Get $get, int $years): string
    {
        $price = ClientOrder::tld($get('domain'))?->priceCents((string) $this->option($get), $years, $this->currency());
        $term = Tld::termLabel($years);

        return $price === null ? $term : "{$term}: ".Money::format($price, $this->currency());
    }

    /**
     * "First payment: €X" (with tax where it applies) for what is chosen, when it can be priced yet. A visitor has no
     * country yet, so their total says tax is added when the tax rules charge it.
     */
    private function summary(): string
    {
        $client = $this->orderClient();

        try {
            $items = app(ClientOrder::class)->items($this->data ?? [], $client);
        } catch (ValidationException) {
            return '';
        }

        $amounts = collect($items)->map(fn (array $item) => $item['first_payment_cents'] ?? $item['price_cents']);

        if ($client === null && config('gaiacp.tax.enabled') && ! Tax::inclusive()) {
            return __('First payment: :amount plus tax where it applies', ['amount' => Money::format($amounts->sum(), $this->currency())]);
        }

        return __('First payment: :amount', ['amount' => Tax::describe(Tax::totals($client, $amounts), $this->currency())]);
    }
}
