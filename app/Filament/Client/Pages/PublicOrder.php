<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Actions\ClientSignup;
use App\Filament\Client\Concerns\OrderForm;
use App\Models\Client;
use App\Models\Coupon;
use App\Support\Coupons;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\OrderCatalogue;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * The public order page (/cart, Settings → Client area → Public order page): visitors add products and domains from
 * the client area's catalogue to the cart (kept in the session, ClientOrder::addToCart()) without logging in. Continue
 * sends them to sign up (or log in while sign up is off); once in, the cart (Cart) opens with it, where they accept
 * the terms and place it, so every order is still placed by a client.
 * Signed-in clients go straight to the Order page. `/cart/<group>` opens a product group and `?product=<id>` a product, for
 * links from a website;
 * the domain search adds `domain_option=transfer&domain=…`. Shown with the public area's side menu (PublicArea).
 * Registered as a route of its own (ClientPanelProvider), so it needs no login.
 */
class PublicOrder extends Page
{
    use OrderForm;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(?string $group = null): void
    {
        abort_unless(ClientOrder::publicEnabled(), 404);

        Coupons::rememberFromLink(); // a `?coupon=CODE` link: entered in the cart

        if (filament()->auth()->check()) {
            $this->redirect(Order::getUrl(array_filter(['group' => $group, ...request()->only('product', 'domain_option', 'domain')], 'is_string')));

            return;
        }

        $option = in_array(request()->query('domain_option'), [ClientOrder::REGISTER, ClientOrder::TRANSFER], true) ? request()->query('domain_option') : ClientOrder::OWN;
        $domain = is_string(request()->query('domain')) ? request()->query('domain') : null;

        $this->fillEmpty(request()->query('product'), $option, $domain, $group);
    }

    private function fillEmpty(mixed $product = null, string $option = ClientOrder::OWN, ?string $domain = null, ?string $group = null): void
    {
        $this->form->fill([
            'group' => OrderCatalogue::keyFor($product) ?? $group,
            'product' => $product ?? ($group === OrderCatalogue::DOMAINS ? ClientOrder::DOMAIN_ONLY : null),
            'options' => ClientOrder::optionDefaults($product),
            'domain_option' => $option,
            'domain' => $domain,
            'years' => $domain !== null && $option !== ClientOrder::OWN ? (ClientOrder::terms($domain, $option)[0] ?? 1) : 1,
            'coupon' => Coupons::remembered(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Order');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Order');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('addToCart'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->orderLayout([
                    Text::make(fn () => $this->summary())
                        ->weight('bold')
                        ->visible(fn () => $this->summary() !== ''),
                ], Action::make('addToCart')->label(__('Add to cart'))->icon(Heroicon::OutlinedShoppingCart)->submit('addToCart'), [
                    Text::make(__('Your cart'))
                        ->weight('bold')
                        ->visible(fn () => ClientOrder::cartCount() > 0),
                    View::make('filament.client.cart-lines')
                        ->visible(fn () => ClientOrder::cartCount() > 0),
                    // Kept for the cart, where it is checked against the client's account.
                    TextInput::make('coupon')
                        ->label('Coupon code')
                        ->maxLength(50)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state) => Coupons::remember($state))
                        ->helperText(fn (?string $state) => blank($state) ? null
                            : (Coupons::problem(Coupons::find($state), null) ?? __('Applied when you place the order.')))
                        ->dehydrated(false)
                        ->visible(fn () => ClientOrder::cartCount() > 0 && Coupon::query()->current()->exists()),
                    Text::make(ClientSignup::enabled()
                        ? __('Add what you need to the cart. Next you create your account (or log in if you are already a client). Then you place the order and get a payment notice by email with how to pay.')
                        : __('Add what you need to the cart. Next you log in to your client account. Then you place the order and get a payment notice by email with how to pay.')),
                ], [
                    Action::make('continue')
                        ->label(ClientSignup::enabled() ? __('Continue to sign up') : __('Continue to log in'))
                        ->color(fn () => ClientOrder::cartCount() > 0 ? 'primary' : 'gray')
                        ->action('continue'),
                ]),
            ]);
    }

    /** Checks the choice as an order would, puts it in the cart and clears the form for the next one. */
    public function addToCart(): void
    {
        abort_unless(ClientOrder::publicEnabled(), 404);

        $data = $this->form->getState();

        try {
            app(ClientOrder::class)->addToCart($data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all());
        }

        Notification::make()->title(__('Added to your cart'))->success()->send();
        $this->fillEmpty();
    }

    /** @return list<array{key: string, title: string, details: list<string>, amount: ?string, error: ?string}> */
    public function lines(): array
    {
        $order = app(ClientOrder::class);

        return collect(ClientOrder::cart())->map(function (array $line, string $key) use ($order) {
            $item = $order->describe($line);

            return ['key' => $key, 'title' => $item['title'], 'details' => $item['details'], 'amount' => $item['amount_cents'] === null ? null : Money::format($item['amount_cents'], Currencies::forClient(null)), 'error' => $item['error']];
        })->values()->all();
    }

    public function removeLine(string $key): void
    {
        ClientOrder::removeFromCart($key);
    }

    /**
     * Goes on to sign up or log in, which come back to the cart. With the cart empty, a choice filled in is added first,
     * so "Continue" alone orders it.
     */
    public function continue(): void
    {
        abort_unless(ClientOrder::publicEnabled(), 404);

        if (ClientOrder::cartCount() === 0 && filled($this->data['product'] ?? null)) {
            $this->addToCart();
        }

        if (ClientOrder::cartCount() === 0) {
            Notification::make()->title(__('Your cart is empty.'))->body(__('Choose something and press Add to cart.'))->warning()->send();

            return;
        }

        session()->put('url.intended', Cart::getUrl());
        session()->put('client_order.from_public', true);

        $this->redirect(ClientSignup::enabled() ? filament()->getRegistrationUrl() : filament()->getLoginUrl());
    }

    protected function orderClient(): ?Client
    {
        return null;
    }
}
