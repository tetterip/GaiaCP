<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\DomainAvailability;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\NewOrderMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Support\Currencies;
use App\Support\Demo;
use App\Support\DemoSnapshot;
use App\Support\DomainName;
use App\Support\DomainSearch;
use App\Support\DomainSsl;
use App\Support\Locales;
use App\Support\ProductOptions;
use App\Support\TermsOfService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * An order a client places in the client area: what they put in the cart, each item one product from the catalogue
 * (Product::clientOrderable(), with a priced cycle), with or without a domain to register or transfer, or a domain on
 * its own (with the SSL certificate Settings → Domains offers for it, DomainSsl, when ticked); all of it one order and one payment notice. Prices always come from
 * the product and the TLD's prices (Settings → Domain pricing), never from the form. Add-ons ticked with the product join
 * the same order on its cycle. It runs through the same PlaceOrder as the admin's
 * New order (payment notice emailed at once, Auto setup decides when it is set up; a domain is registered or
 * transferred only when the order is completed), then emails the admins. While Settings → Client area asks for the terms
 * of service, the order is placed only once they are accepted, and keeps when and which version (TermsOfService).
 */
class ClientOrder
{
    /** The catalogue's choice for "a domain name only". */
    public const DOMAIN_ONLY = 'domain';

    /** What happens with a hosting item's domain. */
    public const OWN = 'own';

    public const REGISTER = 'register';

    public const TRANSFER = 'transfer';

    /**
     * The session key of the cart: what a client or a visitor added on the Order page or the public order page, until
     * the order is placed. Kept across logging in or signing up.
     */
    public const CART = 'client_order.cart';

    /** When the cart's last item was added (a Unix time): in the demo, a cart from before its last reset is emptied. */
    public const CART_AT = 'client_order.cart_at';

    /** The most items one cart holds. */
    public const CART_LIMIT = 20;

    /** The order form's fields a cart item keeps (the EPP code encrypted). */
    private const CART_FIELDS = ['product', 'billing_cycle', 'options', 'addons', 'domain_option', 'domain', 'years', 'epp_code', 'ssl'];

    public function __construct(private PlaceOrder $placeOrder) {}

    /** Settings → Client area → Public order page: visitors choose before they log in (only while clients can order). */
    public static function publicEnabled(): bool
    {
        return (bool) config('gaiacp.client_area.public_orders') && (bool) config('gaiacp.client_area.orders')
            && (self::products()->isNotEmpty() || self::sellsDomains());
    }

    /**
     * Checks a choice of the order form as an order would and adds it to the cart.
     *
     * @param  array<string, mixed>  $data  the order form
     *
     * @throws ValidationException on a choice that can't be ordered, a full cart or a domain already in it
     */
    public function addToCart(array $data, ?Client $client = null): string
    {
        $this->items($data, $client);
        $lines = self::cart();

        if (count($lines) >= self::CART_LIMIT) {
            throw ValidationException::withMessages(['product' => __('Your cart is full. Place this order first, then order the rest.')]);
        }

        if (($domain = self::orderedDomain($data)) !== null && collect($lines)->contains(fn (array $line) => self::orderedDomain($line) === $domain)) {
            throw ValidationException::withMessages(['domain' => __('This domain is already in your cart.')]);
        }

        // A domain known to be taken can't be registered, nor one not registered yet transferred (unknown goes ahead).
        $availability = $domain !== null ? DomainSearch::forOrder($domain) : null;
        $transfer = ($data['domain_option'] ?? null) === self::TRANSFER;

        if (! $transfer && $availability === DomainAvailability::Taken) {
            throw ValidationException::withMessages(['domain' => __('This domain is taken. If it is yours, transfer it instead.')]);
        }

        if ($transfer && $availability === DomainAvailability::Available) {
            throw ValidationException::withMessages(['domain' => __('This domain is not registered yet: register it instead.')]);
        }

        $line = array_filter(array_intersect_key($data, array_flip(self::CART_FIELDS)), fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (filled($line['epp_code'] ?? null)) {
            $line['epp_code'] = Crypt::encryptString((string) $line['epp_code']);
        }

        $key = Str::lower(Str::random(10));
        session()->put(self::CART, [...self::stillCurrent(session()->get(self::CART, [])), $key => $line]);
        session()->put(self::CART_AT, now()->timestamp);

        return $key;
    }

    /** @return array<string, array<string, mixed>> the cart's items by key, in the order they were added (EPP codes decrypted) */
    public static function cart(): array
    {
        $cart = self::stillCurrent(session()->get(self::CART));

        if (! is_array($cart)) {
            return [];
        }

        return collect($cart)->filter(fn ($line) => is_array($line))->map(function (array $line) {
            if (isset($line['epp_code'])) {
                try {
                    $line['epp_code'] = Crypt::decryptString($line['epp_code']);
                } catch (DecryptException) {
                    unset($line['epp_code']);
                }
            }

            return $line;
        })->all();
    }

    public static function cartCount(): int
    {
        return count(self::cart());
    }

    public static function removeFromCart(string $key): void
    {
        $cart = (array) session()->get(self::CART, []);
        unset($cart[$key]);
        session()->put(self::CART, $cart);
    }

    public static function clearCart(): void
    {
        session()->forget([self::CART, self::CART_AT]);
    }

    /**
     * The stored cart, or null once the demo's data was put back after it was filled (the demo keeps sessions, and
     * its visitors expect a fresh start).
     */
    private static function stillCurrent(mixed $cart): mixed
    {
        if ($cart === null || ! Demo::enabled()) {
            return $cart;
        }

        $restoredAt = app(DemoSnapshot::class)->restoredAt();

        if ($restoredAt !== null && (int) session()->get(self::CART_AT, 0) < $restoredAt) {
            self::clearCart();

            return null;
        }

        return $cart;
    }

    /** @return list<string> what is in the cart, by name: each product and domain */
    public static function cartSummary(): array
    {
        return collect(self::cart())->flatMap(function (array $line) {
            $product = (string) ($line['product'] ?? '') === self::DOMAIN_ONLY ? null : self::products()->firstWhere('id', (int) ($line['product'] ?? 0));
            $domain = DomainName::normalize($line['domain'] ?? null);

            return array_filter([$product?->name, $domain !== '' ? $domain : null]);
        })->values()->all();
    }

    /**
     * A cart item as the cart shows it: its name, what goes with it and its first payment, or why it can no longer be
     * ordered (a price or a domain changed since it was added).
     *
     * @param  array<string, mixed>  $line
     * @return array{title: string, details: list<string>, amount_cents: ?int, error: ?string}
     */
    public function describe(array $line, ?Client $client = null): array
    {
        $domainOnly = (string) ($line['product'] ?? '') === self::DOMAIN_ONLY;
        $product = $domainOnly ? null : self::products()->firstWhere('id', (int) ($line['product'] ?? 0));
        $domain = DomainName::normalize($line['domain'] ?? null);
        $title = $product?->name ?? ($domain !== '' ? $domain : __('A domain name only'));

        try {
            $items = $this->items($line, $client);
        } catch (ValidationException $e) {
            return ['title' => $title, 'details' => [], 'amount_cents' => null, 'error' => collect($e->errors())->flatten()->first()];
        }

        $details = collect($items)->map(fn (array $item) => match (true) {
            $item['type'] === ServiceType::Domain => __(':domain: :what for :term', [
                'domain' => $item['domain'],
                'what' => $item['registration_type'] === DomainRegistrationType::Register ? __('registration') : __('transfer'),
                'term' => Tld::termLabel((int) ($line['years'] ?? 1)),
            ]),
            isset($item['parent']) => __('Add-on: :name', ['name' => $item['label']]),
            $domainOnly && $item['type'] === ServiceType::Ssl => __('With :name, :cycle', ['name' => $item['label'], 'cycle' => $item['billing_cycle']->getLabel()]),
            default => trim(($item['domain'] ? $item['domain'].', ' : '').$item['billing_cycle']->getLabel()),
        })->all();

        return [
            'title' => $title,
            'details' => array_values($details),
            'amount_cents' => collect($items)->sum(fn (array $item) => $item['first_payment_cents'] ?? $item['price_cents']),
            'error' => null,
        ];
    }

    /**
     * The items of several cart items together, for one order: each item's add-ons point at its own product, and a
     * domain is registered or transferred only once.
     *
     * @param  array<array-key, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException naming the item that can't be ordered
     */
    public function cartItems(array $lines, ?Client $client = null): array
    {
        $items = [];
        $domains = [];

        foreach ($lines as $line) {
            try {
                $lineItems = $this->items($line, $client);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['cart' => __(':item: :problem', [
                    'item' => $this->describe($line, $client)['title'],
                    'problem' => collect($e->errors())->flatten()->first(),
                ])]);
            }

            if (($domain = self::orderedDomain($line)) !== null) {
                if (in_array($domain, $domains, true)) {
                    throw ValidationException::withMessages(['cart' => __(':item: :problem', ['item' => $domain, 'problem' => __('This domain is already in your cart.')])]);
                }
                $domains[] = $domain;
            }

            $offset = count($items);
            foreach ($lineItems as $item) {
                if (isset($item['parent'])) {
                    $item['parent'] += $offset;
                }
                $items[] = $item;
            }
        }

        if ($items === []) {
            throw ValidationException::withMessages(['cart' => __('Your cart is empty.')]);
        }

        return $items;
    }

    /** The domain a choice registers or transfers, if any. */
    private static function orderedDomain(array $data): ?string
    {
        $product = (string) ($data['product'] ?? '');
        $option = $data['domain_option'] ?? self::OWN;
        $registers = $product === self::DOMAIN_ONLY || in_array($option, [self::REGISTER, self::TRANSFER], true);
        $domain = DomainName::normalize($data['domain'] ?? null);

        return $registers && $domain !== '' ? $domain : null;
    }

    /** @return Collection<int, Product> what a client can order: shown in the client area, with a priced cycle; domains are ordered by name */
    public static function products(): Collection
    {
        return Product::query()->clientOrderable()->where('type', '!=', ServiceType::Domain)->orderBy('type')->orderBy('name')->get()
            ->filter(fn (Product $product) => $product->cycles() !== [])
            ->values();
    }

    /** Whether a TLD is offered with a registration or transfer price, so "a domain name only" is offered. */
    public static function sellsDomains(): bool
    {
        return Tld::query()->active()->get()->contains(fn (Tld $tld) => $tld->terms('register') !== [] || $tld->terms('transfer') !== []);
    }

    /** The TLD row that prices this domain (Settings → Domain pricing), if it is offered. */
    public static function tld(?string $domain): ?Tld
    {
        return Tld::match($domain);
    }

    /** @return list<int> the terms in years this domain can be registered or transferred for */
    public static function terms(?string $domain, string $kind): array
    {
        return self::tld($domain)?->terms($kind) ?? [];
    }

    /**
     * The items PlaceOrder gets, priced here in the client's currency.
     *
     * @param  array{product?: string|int|null, billing_cycle?: string|null, options?: array<string, mixed>|null, addons?: list<int|string>|null, domain?: string|null, domain_option?: string|null, years?: int|string|null, epp_code?: string|null, ssl?: bool|null}  $data
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException when the choice is not orderable (the form shows the message on its field)
     */
    public function items(array $data, ?Client $client = null): array
    {
        $currency = Currencies::forClient($client);
        $domainOnly = (string) ($data['product'] ?? '') === self::DOMAIN_ONLY;
        $option = $data['domain_option'] ?? self::OWN;
        // A domain on its own is registered or transferred; "I already have it" is only for hosting.
        $option = $domainOnly && ! in_array($option, [self::REGISTER, self::TRANSFER], true) ? self::REGISTER : $option;
        $domain = DomainName::normalize($data['domain'] ?? null);
        $product = $domainOnly ? null : self::products()->firstWhere('id', (int) ($data['product'] ?? 0));
        $items = [];

        if ($domain === '' && ($domainOnly || in_array($product?->type, [ServiceType::Hosting, ServiceType::Ssl], true))) {
            throw ValidationException::withMessages(['domain' => __('Enter the domain name.')]);
        }

        if (! $domainOnly) {
            $product ??= throw ValidationException::withMessages(['product' => __('Choose something to order.')]);
            $cycle = BillingCycle::tryFrom((string) ($data['billing_cycle'] ?? '')) ?? $product->defaultCycle();
            $price = $product->priceFor($cycle, $currency) ?? throw ValidationException::withMessages(['billing_cycle' => __('Choose a billing cycle.')]);
            $values = self::optionValues($product, (array) ($data['options'] ?? []));
            $picks = ProductOptions::select($product, $values, $cycle, $currency);
            $price += ProductOptions::total($picks);
            $setup = $product->setupFeeFor($cycle, $currency) + ProductOptions::setupTotal($picks);

            $items[] = [
                'product_id' => $product->id,
                'type' => $product->type,
                'label' => $product->name,
                'domain' => in_array($product->type, [ServiceType::Hosting, ServiceType::Ssl], true) ? $domain : null,
                'server_id' => $product->type->needsServer() ? $product->pickServer()?->id : null,
                'package' => $product->package,
                'billing_cycle' => $cycle,
                'options' => $values,
                'price_cents' => $price,
                'first_payment_cents' => $setup > 0 ? $price + $setup : null,
            ];

            array_push($items, ...self::addonItems($product, $cycle, $currency, (array) ($data['addons'] ?? [])));

            if ($product->type !== ServiceType::Hosting) {
                return $items;
            }
        }

        if (! in_array($option, [self::REGISTER, self::TRANSFER], true)) {
            return $items;
        }

        $years = (int) ($data['years'] ?? 1);
        $tld = self::tld($domain);
        $first = $tld?->priceCents($option, $years, $currency);

        if ($first === null) {
            throw ValidationException::withMessages(['domain' => $tld
                ? ($option === self::REGISTER
                    ? __('This domain cannot be registered for :years year(s). Choose another term.', ['years' => $years])
                    : __('This domain cannot be transferred for :years year(s). Choose another term.', ['years' => $years]))
                : __('We do not offer domains ending like this one.')]);
        }

        if (Service::query()->where('type', ServiceType::Domain)->where('domain', $domain)->whereNotIn('status', [ServiceStatus::Cancelled, ServiceStatus::Terminated])->exists()) {
            throw ValidationException::withMessages(['domain' => __('This domain is already with us. Contact us if it is yours.')]);
        }

        $items[] = [
            'type' => ServiceType::Domain,
            'domain' => $domain,
            'registration_type' => $option === self::REGISTER ? DomainRegistrationType::Register : DomainRegistrationType::Transfer,
            'registrar_id' => $tld->registrar_id, // the TLD's preferred registrar
            'epp_code' => $option === self::TRANSFER ? ($data['epp_code'] ?? null) : null,
            'billing_cycle' => BillingCycle::forYears($years),
            'price_cents' => $tld->priceCents('renew', $years, $currency) ?? $first,
            'first_payment_cents' => $first,
        ];

        // A domain on its own may bring the SSL certificate Settings → Domains offers with it (DomainSsl).
        if ($domainOnly && ! empty($data['ssl'])) {
            $items[] = DomainSsl::item($domain, $currency)
                ?? throw ValidationException::withMessages(['ssl' => __('The SSL certificate offered with domains is no longer available. Untick it.')]);
        }

        return $items;
    }

    /**
     * The add-ons ticked with the product (ClientAddons::offered()), on its cycle: pending in the same order, owed
     * with its first payment (setup fee included) and active with it.
     *
     * @param  array<int|string, mixed>  $ids
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException on an add-on not offered with it
     */
    private static function addonItems(Product $product, BillingCycle $cycle, string $currency, array $ids): array
    {
        $ids = collect($ids)->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        $offered = ClientAddons::offered($product, $cycle, $currency)->keyBy('id');

        if ($ids->contains(fn (int $id) => ! $offered->has($id))) {
            throw ValidationException::withMessages(['addons' => __('This add-on can\'t be added to this product.')]);
        }

        return $ids->map(function (int $id) use ($offered, $cycle, $currency) {
            $addon = $offered[$id];
            $price = (int) $addon->priceFor($cycle, $currency);
            $setup = (int) $addon->setupFeeFor($cycle, $currency);

            return [
                'parent' => 0,
                'product_id' => $addon->id,
                'type' => $addon->type,
                'label' => $addon->name,
                'billing_cycle' => $cycle,
                'price_cents' => $price,
                'first_payment_cents' => $setup > 0 ? $price + $setup : null,
            ];
        })->values()->all();
    }

    /**
     * The first pick of each option a client sees, for a product chosen through a link.
     *
     * @return array<string, mixed>
     */
    public static function optionDefaults(mixed $product): array
    {
        return ProductOptions::defaults(ProductOptions::for(self::products()->firstWhere('id', (int) $product), hiddenToo: false));
    }

    /**
     * The client's picks of the product's options: only those shown to clients, each a choice it offers.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException on a choice the option doesn't have
     */
    private static function optionValues(Product $product, array $values): array
    {
        $picked = [];

        foreach (ProductOptions::for($product, hiddenToo: false) as $option) {
            $value = $values[$option['id']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($option['type'], ['select', 'radio'], true) && ! collect($option['choices'] ?? [])->contains('id', $value)) {
                throw ValidationException::withMessages(["options.{$option['id']}" => __('Choose one of the options.')]);
            }

            if ($option['type'] === 'quantity' && (! is_numeric($value) || (int) $value < (int) ($option['min'] ?? 0)
                || (filled($option['max'] ?? null) && (int) $value > (int) $option['max']))) {
                throw ValidationException::withMessages(["options.{$option['id']}" => filled($option['max'] ?? null)
                    ? __('Enter a number from :min to :max.', ['min' => (int) ($option['min'] ?? 0), 'max' => (int) $option['max']])
                    : __('Enter a number from :min up.', ['min' => (int) ($option['min'] ?? 0)])]);
            }

            $picked[$option['id']] = $value;
        }

        return $picked;
    }

    /**
     * Places the cart's items (`lines`), or one choice of the order form, as one order with one payment notice.
     *
     * @param  array<string, mixed>  $data  `lines` (or the order form's fields), `notes`, `accept_terms`
     */
    public function place(Client $client, array $data): Order
    {
        $items = isset($data['lines']) ? $this->cartItems($data['lines'], $client) : $this->items($data, $client);
        $terms = [];

        if (TermsOfService::required()) {
            if (! filter_var($data['accept_terms'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['accept_terms' => __('Please accept the Terms of Service to continue.')]);
            }

            $terms = TermsOfService::acceptance();
        }

        $order = $this->placeOrder->handle($client, $items, filled($data['notes'] ?? null) ? trim($data['notes']) : null, $terms);

        ActivityLog::record('order.client_placed', "Order #{$order->id} placed in the client area", client: $client, properties: array_filter(['order_id' => $order->id, 'terms_version' => $order->terms_version]));
        $this->notifyAdmins($order);

        return $order;
    }

    /** Settings → Automation → New order email, to the admins with Receive system emails on who open orders. */
    public function notifyAdmins(Order $order): void
    {
        if (! config('gaiacp.automation.new_order_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Clients));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new NewOrderMail($order));
            } catch (Throwable $e) {
                Log::error('New order email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "New order email to {$admin->email} failed: {$e->getMessage()}", client: $order->client);
            }
        }
    }
}
