<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Tld;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\PublicArea;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

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

    /** @return list<array{tld: string, sale: ?string, register: string|HtmlString|null, transfer: string|HtmlString|null, renew: string|HtmlString|null}> */
    public function rows(): array
    {
        $currency = Currencies::forClient(filament()->auth()->user()?->client);

        return array_map(fn (Tld $tld) => [
            'tld' => $tld->tld,
            'sale' => $tld->onSale() ? ($tld->saleUntil() ?? __('Sale')) : null,
            'register' => $this->price($tld, 'register', $currency),
            'transfer' => $this->price($tld, 'transfer', $currency),
            'renew' => $this->price($tld, 'renew', $currency),
        ], PublicArea::pricedTlds());
    }

    /**
     * "€12.00 for 1 year" (or "Free for 2 years"): the price for the shortest term, which is always named; on sale the
     * normal price struck through before it. Null when not offered.
     */
    private function price(Tld $tld, string $kind, string $currency): string|HtmlString|null
    {
        $years = $tld->terms($kind)[0] ?? null;

        if ($years === null) {
            return null;
        }

        $cents = (int) $tld->orderPriceCents($kind, $years, $currency);
        $price = $cents === 0 ? __('Free') : Money::format($cents, $currency);
        $price = self::withTerm($price, Tld::termLabel($years));

        return $tld->salePriceCents($kind, $years, $currency) === null
            ? $price
            : new HtmlString('<s class="gcp-was">'.e(Money::format((int) $tld->priceCents($kind, $years, $currency), $currency)).'</s> '.e($price));
    }

    /**
     * ":price for :term" with two unbroken pieces, the price and the rest ("€22.00" and "for 1 year"; "1 yıl için" and
     * "€22.00" in Turkish), so a narrow cell puts the term on a line of its own.
     */
    private static function withTerm(string $price, string $term): string
    {
        [$before, $after] = explode("\0", __(':price for :term', ['price' => "\0", 'term' => $term]), 2) + [1 => ''];
        $unbroken = fn (string $text) => str_replace(' ', "\u{00A0}", trim($text));

        return trim($unbroken($before).' '.$unbroken($price).' '.$unbroken($after));
    }
}
