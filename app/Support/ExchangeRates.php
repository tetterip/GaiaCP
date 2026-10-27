<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Tld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Settings → Currencies' two buttons. Update exchange rates reads the European Central Bank's daily reference rates
 * (free, no key; quoted against EUR, so any base currency they list works) and sets every other currency's rate;
 * Update product prices sets every product's and TLD's prices in the other currencies to the base ones at those rates.
 */
class ExchangeRates
{
    public const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /**
     * @return array{updated: array<string, string>, missing: list<string>} code => new rate, and the codes the ECB does not quote
     *
     * @throws RuntimeException when the rates cannot be read or the ECB does not quote the base currency
     */
    public function update(): array
    {
        $quotes = $this->quotes();
        $base = Currencies::base();
        $baseQuote = $quotes[$base] ?? throw new RuntimeException(__('The European Central Bank does not quote :currency, the base currency. Set the rates by hand.', ['currency' => $base]));
        $updated = [];
        $missing = [];

        foreach (Currency::query()->where('code', '!=', $base)->orderBy('code')->get() as $currency) {
            if (! isset($quotes[$currency->code])) {
                $missing[] = $currency->code;

                continue;
            }

            $currency->update(['rate' => round($quotes[$currency->code] / $baseQuote, 5)]);
            $updated[$currency->code] = (string) $currency->rate;
        }

        ActivityLog::record('currency.rates_updated', 'Exchange rates updated from the ECB: '
            .(collect($updated)->map(fn (string $rate, string $code) => "{$code} {$rate}")->join(', ') ?: 'none')
            .($missing ? '; not quoted: '.implode(', ', $missing) : ''), properties: ['rates' => $updated, 'missing' => $missing]);

        return ['updated' => $updated, 'missing' => $missing];
    }

    /** @return int how many products and TLDs got new prices */
    public function updatePrices(): int
    {
        $count = 0;

        DB::transaction(function () use (&$count) {
            foreach (Product::query()->get() as $product) {
                $product->convertPrices();
                $count++;
            }

            foreach (Tld::query()->get() as $tld) {
                $tld->convertPrices();
                $count++;
            }
        });

        ActivityLog::record('currency.prices_updated', "Prices in other currencies updated at today's rates for {$count} product(s) and TLD(s)");

        return $count;
    }

    /** @return array<string, float> code => units per 1 EUR, EUR included */
    private function quotes(): array
    {
        try {
            $body = Http::timeout(20)
                ->withMiddleware(ModuleLogger::middleware('currencies', 'ecb_rates'))
                ->get(self::ECB_URL)
                ->throw()
                ->body();
        } catch (Throwable $e) {
            throw new RuntimeException(__('Could not read the exchange rates: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        preg_match_all("/currency=['\"]([A-Z]{3})['\"]\\s+rate=['\"]([0-9.]+)['\"]/", $body, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            throw new RuntimeException(__('Could not read the exchange rates: :error', ['error' => 'no rates in the reply']));
        }

        return ['EUR' => 1.0, ...collect($matches)->mapWithKeys(fn (array $m) => [$m[1] => (float) $m[2]])->all()];
    }
}
