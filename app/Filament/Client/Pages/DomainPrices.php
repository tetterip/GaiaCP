<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Tld;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\PublicArea;
use Filament\Pages\Page;

/**
 * Domain prices (Settings → Public area): each active TLD's price to register, transfer and renew, for its shortest
 * term, with a box that filters the list. Open to visitors and to signed-in clients (in their currency).
 */
class DomainPrices extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static ?string $title = 'Domain prices';

    protected static ?string $slug = 'domain-prices';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.client.domain-prices';

    public static function canAccess(): bool
    {
        return PublicArea::domainPrices();
    }

    /** @return list<array{tld: string, register: ?string, transfer: ?string, renew: ?string}> */
    public function rows(): array
    {
        $currency = Currencies::forClient(filament()->auth()->user()?->client);

        return array_map(fn (Tld $tld) => [
            'tld' => $tld->tld,
            'register' => $this->price($tld, 'register', $currency),
            'transfer' => $this->price($tld, 'transfer', $currency),
            'renew' => $this->price($tld, 'renew', $currency),
        ], PublicArea::pricedTlds());
    }

    /** "€12.00" (or "Free"), "€24.00 for 2 years" when the shortest term is longer than a year; null when not offered. */
    private function price(Tld $tld, string $kind, string $currency): ?string
    {
        $years = $tld->terms($kind)[0] ?? null;

        if ($years === null) {
            return null;
        }

        $cents = (int) $tld->priceCents($kind, $years, $currency);
        $price = $cents === 0 ? __('Free') : Money::format($cents, $currency);

        return $years === 1 ? $price : __(':price for :term', ['price' => $price, 'term' => Tld::termLabel($years)]);
    }
}
