<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Filament\Client\Concerns\OrderForm;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Support\Coupons;
use App\Support\OrderCatalogue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The client area's Order page (Settings → Client area → Clients can order): one product from the catalogue, its
 * billing cycle and its domain (picking its group first while there are several), or a domain on its own, added to the cart (Cart), where the client adds more and
 * places them as one order. ClientOrder checks and prices each.
 */
class Order extends Page
{
    use OrderForm;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $title = 'Order';

    protected static ?string $slug = 'order';

    protected static ?int $navigationSort = -1; // right after Home

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) config('gaiacp.client_area.orders') && (ClientOrder::products()->isNotEmpty() || ClientOrder::sellsDomains());
    }

    /**
     * "Order a new service" over the client's Services and Domains lists and in their empty state, while clients can
     * order. A domains list opens the domain search (or the Order page on "A domain name only" when no TLD is sold
     * for registration, only transfers).
     */
    public static function callToAction(bool $domain = false): Action
    {
        return Action::make($domain ? 'orderDomain' : 'orderService')
            ->label($domain ? __('Order a domain') : __('Order a new service'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(fn () => match (true) {
                $domain && SearchDomains::canAccess() => SearchDomains::getUrl(),
                $domain && ClientOrder::sellsDomains() => static::getUrl(['group' => OrderCatalogue::DOMAINS]),
                default => static::getUrl(),
            })
            ->visible(fn () => static::canAccess() && ($domain ? ClientOrder::sellsDomains() : true));
    }

    /** `/order/<group>` opens the order on a product group (Settings → Product groups), a hidden one too. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/order/{group?}';
    }

    public function mount(?string $group = null): void
    {
        Coupons::rememberFromLink(); // a `?coupon=CODE` link: entered in the cart
        $option = in_array(request()->query('domain_option'), [ClientOrder::REGISTER, ClientOrder::TRANSFER], true) ? request()->query('domain_option') : ClientOrder::OWN;
        $domain = is_string(request()->query('domain')) ? request()->query('domain') : null;
        $product = request()->query('product');

        $this->form->fill([
            'group' => OrderCatalogue::keyFor($product) ?? $group,
            'product' => $product ?? ($group === OrderCatalogue::DOMAINS ? ClientOrder::DOMAIN_ONLY : null),
            'options' => ClientOrder::optionDefaults(request()->query('product')),
            // The domain search opens it on a domain to transfer.
            'domain_option' => $option,
            'domain' => $domain,
            'years' => $domain !== null && $option !== ClientOrder::OWN ? (ClientOrder::terms($domain, $option)[0] ?? 1) : 1,
        ]);
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
                    Text::make(fn () => trans_choice('{0} Your cart is empty.|{1} Your cart has 1 item.|[2,*] Your cart has :count items.', ClientOrder::cartCount())),
                ], [
                    Action::make('viewCart')
                        ->label(__('Go to cart'))
                        ->color('gray')
                        ->url(fn () => Cart::getUrl())
                        ->visible(fn () => ClientOrder::cartCount() > 0),
                ]),
            ]);
    }

    /** Checks the choice as an order would, puts it in the cart and opens the cart. */
    public function addToCart(): void
    {
        $data = $this->form->getState();

        try {
            app(ClientOrder::class)->addToCart($data, $this->orderClient());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all());
        }

        Notification::make()->title(__('Added to your cart'))->success()->send();
        $this->redirect(Cart::getUrl());
    }

    protected function orderClient(): ?Client
    {
        return filament()->auth()->user()?->client;
    }
}
