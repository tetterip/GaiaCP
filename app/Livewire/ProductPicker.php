<?php

namespace App\Livewire;

use App\Actions\ClientAddons;
use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Filament\Client\Pages\Order;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tld;
use App\Support\Currencies;
use App\Support\DomainName;
use App\Support\Money;
use App\Support\OrderCatalogue;
use App\Support\ProductOptions;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "New hosting" / "New service" over the client's Hosting and Other services lists: the products a client can order of
 * that kind, by product group (a chip each), each card with its cycle and price and Add to cart. A product that needs a
 * domain asks for it in the card first (one they have, or one to register with it); one with options or add-ons to
 * choose also links to the full Order page. Adding tells the list (`product-added`), which closes the window and opens
 * the cart from the side.
 */
class ProductPicker extends Component
{
    /** hosting, or other (every orderable product but hosting and domains). */
    public string $kind = 'hosting';

    public ?string $group = null;

    /** @var array<int|string, string> product id => the cycle picked on its card */
    public array $cycles = [];

    /** The product whose card asks for a domain, after Add to cart. */
    public ?int $pending = null;

    public string $domain = '';

    /** own (a domain they have) or register (with this order). */
    public string $domainOption = ClientOrder::OWN;

    public function mount(string $kind = 'hosting'): void
    {
        $this->kind = $kind === 'other' ? 'other' : 'hosting';
        $this->group = $this->groups()->keys()->first();
    }

    /** @return Collection<string, array{key: string, name: string, products: Collection<int, Product>}> the product groups with products of this kind */
    public function groups(): Collection
    {
        return OrderCatalogue::groups()
            ->except(OrderCatalogue::DOMAINS)
            ->map(fn (array $group) => ['key' => $group['key'], 'name' => $group['name'], 'products' => $group['products']
                ->filter(fn (Product $product) => ! $product->addon && ($product->type === ServiceType::Hosting) === ($this->kind === 'hosting'))
                ->values()])
            ->filter(fn (array $group) => $group['products']->isNotEmpty());
    }

    public static function offersAnything(string $kind): bool
    {
        $picker = new self;
        $picker->kind = $kind;

        return $picker->groups()->isNotEmpty();
    }

    /** @return Collection<int, Product> the open group's products */
    public function products(): Collection
    {
        $groups = $this->groups();

        return ($groups[(string) $this->group] ?? $groups->first())['products'] ?? collect();
    }

    public function pickGroup(string $key): void
    {
        $this->group = $key;
        $this->pending = null;
    }

    /** @return array<string, string> cycle => its price on the card ("€60.00 / year"), with the setup fee when there is one */
    public function cycleOptions(Product $product): array
    {
        $currency = $this->currency();

        return collect($product->cycles())->mapWithKeys(function (BillingCycle $cycle) use ($product, $currency) {
            $price = Money::format((int) $product->orderPriceFor($cycle, $currency), $currency);
            $setup = $product->setupFeeFor($cycle, $currency);
            $label = __(':choice · :amount', ['choice' => $cycle->getLabel(), 'amount' => $price]);

            return [$cycle->value => $setup > 0 ? __(':price + :setup setup', ['price' => $label, 'setup' => Money::format($setup, $currency)]) : $label];
        })->all();
    }

    public function cycleOf(Product $product): string
    {
        $offered = array_map(fn (BillingCycle $cycle) => $cycle->value, $product->cycles());
        $picked = $this->cycles[$product->id] ?? null;

        return in_array($picked, $offered, true) ? $picked : (string) $product->defaultCycle()?->value;
    }

    /** A short description: its first lines, without the HTML. */
    public function summary(Product $product): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>|</(p|div|li|h[1-6])>~i', "\n", (string) $product->localDescription())), ENT_QUOTES | ENT_HTML5);

        return Str::limit(collect(preg_split('/\R/u', $text) ?: [])->map(fn (string $line) => trim($line))->filter()->take(3)->implode(' · '), 160);
    }

    /** Whether it asks for a domain: hosting and SSL certificates are for one. */
    public function needsDomain(Product $product): bool
    {
        return in_array($product->type, [ServiceType::Hosting, ServiceType::Ssl], true);
    }

    /** Options or add-ons to choose: the full Order page has them (Add to cart here takes the options' defaults). */
    public function hasMoreChoices(Product $product): bool
    {
        $cycle = BillingCycle::tryFrom($this->cycleOf($product));

        return ProductOptions::for($product, hiddenToo: false) !== []
            || ($cycle !== null && ClientAddons::offered($product, $cycle, $this->currency())->isNotEmpty());
    }

    public function orderUrl(Product $product): string
    {
        return Order::getUrl(['product' => $product->id]);
    }

    /** Whether the domain typed could be registered with the order (a TLD with a registration price). */
    public function canRegister(): bool
    {
        return ClientOrder::sellsDomains();
    }

    public function add(int $productId): void
    {
        $product = $this->groups()->flatMap(fn (array $group) => $group['products'])->firstWhere('id', $productId);

        if ($product === null) {
            return;
        }

        if ($this->needsDomain($product) && $this->pending !== $productId) {
            $this->pending = $productId;
            $this->domain = '';
            $this->domainOption = ClientOrder::OWN;
            $this->resetErrorBag();

            return;
        }

        $register = $this->domainOption === ClientOrder::REGISTER && $product->type === ServiceType::Hosting;
        $domain = DomainName::normalize($this->domain);
        $this->resetErrorBag();

        try {
            app(ClientOrder::class)->addToCart(array_filter([
                'product' => (string) $product->id,
                'billing_cycle' => $this->cycleOf($product),
                'options' => ClientOrder::optionDefaults($product->id),
                'domain_option' => $this->needsDomain($product) ? ($register ? ClientOrder::REGISTER : ClientOrder::OWN) : null,
                'domain' => $this->needsDomain($product) ? $domain : null,
                'years' => $register ? (Tld::match($domain)?->terms('register')[0] ?? 1) : null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []), $this->client());
        } catch (ValidationException $e) {
            $this->addError('domain', collect($e->errors())->flatten()->first());

            return;
        }

        $this->pending = null;
        $this->domain = '';
        Notification::make()->title(__('Added to your cart'))->success()->send();
        $this->dispatch('cart-updated');
        $this->dispatch('product-added');
    }

    public function cancelDomain(): void
    {
        $this->pending = null;
        $this->resetErrorBag();
    }

    private function client(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    private function currency(): string
    {
        return Currencies::forClient($this->client());
    }

    public function render()
    {
        return view('livewire.product-picker');
    }
}
