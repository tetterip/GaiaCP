<?php

namespace App\Filament\Client\Concerns;

use App\Actions\ClientOrder;
use App\Models\Client;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The cart's items as filament.client.cart-lines shows them (Cart, PublicOrder, the top bar's CartDrawer): name, what
 * goes with it, first payment, the billing cycle and domain term to change in place, and Remove. Each change tells the
 * other cart views on the page (`cart-updated`).
 */
trait ShowsCartLines
{
    /** The client ordering, or null for a visitor on the public order page. */
    abstract protected function cartClient(): ?Client;

    /** @return list<array{key: string, title: string, details: list<string>, amount: ?string, error: ?string, choices: list<array{field: string, label: string, value: string, options: array<string, string>}>}> */
    public function lines(): array
    {
        $order = app(ClientOrder::class);
        $client = $this->cartClient();
        $currency = Currencies::forClient($client);

        return collect(ClientOrder::cart())->map(function (array $line, string $key) use ($order, $client, $currency) {
            $item = $order->describe($line, $client);

            return [
                'key' => $key,
                'title' => $item['title'],
                'details' => $item['details'],
                'amount' => $item['amount_cents'] === null ? null : Money::format($item['amount_cents'], $currency),
                'error' => $item['error'],
                'choices' => $item['error'] === null ? $order->cartChoices($line, $client) : [],
            ];
        })->values()->all();
    }

    public function removeLine(string $key): void
    {
        ClientOrder::removeFromCart($key);
        $this->dispatch('cart-updated');
    }

    /** A billing cycle or domain term picked on a cart item. */
    public function changeLine(string $key, string $field, string $value): void
    {
        try {
            app(ClientOrder::class)->changeCartLine($key, $field, $value, $this->cartClient());
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        $this->dispatch('cart-updated');
    }
}
