<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Support\Coupons;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\Tax;
use App\Support\TermsOfService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The client's cart: what they added on the Order page (or on the public order page before logging in), each with its
 * first payment and a Remove button, then notes, a coupon code (shown while a coupon is current; also kept from a
 * `?coupon=CODE` link, App\Support\Coupons), the terms and Place order, which places all of it as one order with one
 * payment notice (ClientOrder::place()). Opened from the cart icon in the top bar.
 */
class Cart extends Page
{
    use TranslatesPageLabels;

    protected static ?string $title = 'Cart';

    protected static ?string $slug = 'checkout';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Order::canAccess();
    }

    public function mount(): void
    {
        // A `?coupon=CODE` link, or a code entered before.
        Coupons::rememberFromLink();
        $this->form->fill(['coupon' => Coupons::remembered()]);

        if ($this->lines() === []) {
            return;
        }

        // Back from signing up or logging in with a cart chosen on the public order page
        if (session()->pull('client_order.from_public')) {
            Notification::make()
                ->title(__('Your order is ready to place'))
                ->body(__('Check what you chose and place the order.'))
                ->info()
                ->send();
        }
    }

    /** @return list<array{key: string, title: string, details: list<string>, amount: ?string, error: ?string}> */
    public function lines(): array
    {
        $order = app(ClientOrder::class);

        return collect(ClientOrder::cart())->map(function (array $line, string $key) use ($order) {
            $item = $order->describe($line, $this->client());

            return [
                'key' => $key,
                'title' => $item['title'],
                'details' => $item['details'],
                'amount' => $item['amount_cents'] === null ? null : Money::format($item['amount_cents'], $this->currency()),
                'error' => $item['error'],
            ];
        })->values()->all();
    }

    public function removeLine(string $key): void
    {
        ClientOrder::removeFromCart($key);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('placeOrder'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make()
                            ->schema([
                                View::make('filament.client.cart-lines'),
                                Actions::make([
                                    Action::make('orderMore')
                                        ->label(__('Order something else'))
                                        ->icon(Heroicon::OutlinedPlus)
                                        ->color('gray')
                                        ->url(fn () => Order::getUrl()),
                                ]),
                            ])
                            ->columnSpan(['lg' => 2]),
                        Section::make()
                            ->schema([
                                Textarea::make('notes')
                                    ->label('Anything we should know?')
                                    ->rows(2)
                                    ->maxLength(2000),
                                TextInput::make('coupon')
                                    ->label('Coupon code')
                                    ->maxLength(50)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state) => Coupons::remember($state))
                                    ->suffixAction(Action::make('applyCoupon')
                                        ->label(__('Apply'))
                                        ->icon(Heroicon::OutlinedCheck)
                                        ->action(fn (?string $state) => Coupons::remember($state)))
                                    ->helperText(fn (?string $state) => $this->couponNote($state))
                                    ->visible(fn () => Coupon::query()->current()->exists())
                                    ->dehydrateStateUsing(fn (?string $state) => Coupon::normalize($state) ?: null),
                                Text::make(fn () => $this->total())
                                    ->weight('bold'),
                                Text::make(__('You get a payment notice by email with how to pay.')),
                                TermsOfService::checkbox(),
                                Actions::make([
                                    Action::make('placeOrder')
                                        ->label(__('Place order'))
                                        ->submit('placeOrder')
                                        ->disabled(fn () => $this->lines() === []),
                                ])->fullWidth(),
                            ])
                            ->extraAttributes(['class' => 'gcp-order-summary'])
                            ->columnSpan(1),
                    ]),
            ]);
    }

    /** "First payment: €X" for the whole cart, with tax where it applies. */
    public function total(): string
    {
        try {
            $items = app(ClientOrder::class)->cartItems(ClientOrder::cart(), $this->client());
        } catch (ValidationException) {
            return '';
        }

        $coupon = $this->coupon();

        if ($coupon !== null && Coupons::problem($coupon, $this->client(), $items) === null) {
            $items = Coupons::apply($coupon, $items, $this->currency())['items'];
        }

        $amounts = collect($items)->map(fn (array $item) => $item['first_payment_cents'] ?? $item['price_cents']);

        return __('First payment: :amount', ['amount' => Tax::describe(Tax::totals($this->client(), $amounts), $this->currency())]);
    }

    /** What the code entered does: what it takes off, or why it can't be used. */
    public function couponNote(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $coupon = Coupons::find($code);

        try {
            $items = app(ClientOrder::class)->cartItems(ClientOrder::cart(), $this->client());
        } catch (ValidationException) {
            $items = null;
        }

        if (($problem = Coupons::problem($coupon, $this->client(), $items)) !== null) {
            return $problem;
        }

        if ($items === null) {
            return $coupon->recurring
                ? __(':value, renewals too', ['value' => $coupon->describeValue($this->currency())])
                : __(':value, first payment', ['value' => $coupon->describeValue($this->currency())]);
        }

        $saved = Coupons::apply($coupon, $items, $this->currency())['discount_cents'];

        return $coupon->recurring
            ? __(':value off, on every renewal too. You save :amount now.', ['value' => $coupon->describeValue($this->currency()), 'amount' => Money::format($saved, $this->currency())])
            : __(':value off the first payment. You save :amount.', ['value' => $coupon->describeValue($this->currency()), 'amount' => Money::format($saved, $this->currency())]);
    }

    private function coupon(): ?Coupon
    {
        return Coupons::find($this->data['coupon'] ?? null);
    }

    public function placeOrder(): void
    {
        $data = $this->form->getState();

        try {
            $order = app(ClientOrder::class)->place($this->client(), ['lines' => ClientOrder::cart()] + $data);
        } catch (ValidationException $e) {
            foreach (['accept_terms', 'coupon'] as $field) {
                if (isset($e->errors()[$field])) {
                    throw ValidationException::withMessages(["data.{$field}" => $e->errors()[$field]]);
                }
            }

            Notification::make()
                ->title(__('The order was not placed'))
                ->body(collect($e->errors())->flatten()->first())
                ->danger()
                ->send();

            return;
        }

        ClientOrder::clearCart();
        Coupons::remember(null);

        Notification::make()
            ->title(__('Thank you! Your order #:number is placed.', ['number' => $order->id]))
            ->body(__('We emailed you its payment notice with how to pay.'))
            ->success()
            ->persistent()
            ->send();

        $notice = Invoice::query()->where('order_id', $order->id)->first();

        $this->redirect($notice ? PaymentNoticeResource::getUrl('view', ['record' => $notice]) : ServiceResource::getUrl());
    }

    private function client(): Client
    {
        return filament()->auth()->user()->client;
    }

    private function currency(): string
    {
        return Currencies::forClient($this->client());
    }
}
