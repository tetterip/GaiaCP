<?php

namespace App\Models\Concerns;

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Sale prices from sale_starts_on (empty = already) to sale_ends_on (empty = until they are removed), both days
 * included. Outside those days the normal prices apply again by themselves: nothing is changed when a sale ends.
 *
 * @property CarbonImmutable|null $sale_starts_on
 * @property CarbonImmutable|null $sale_ends_on
 */
trait HasSale
{
    /** Whether its sale prices apply today: it has one, and today is within the sale's days. */
    public function onSale(?CarbonInterface $today = null): bool
    {
        $today = CarbonImmutable::parse($today ?? CarbonImmutable::today())->startOfDay();

        return $this->hasSalePrices()
            && ($this->sale_starts_on === null || $this->sale_starts_on->lte($today))
            && ($this->sale_ends_on === null || $this->sale_ends_on->gte($today));
    }

    /** "Sale until 30/11/2026" while its sale runs with an end day, else null. */
    public function saleUntil(): ?string
    {
        return $this->onSale() && $this->sale_ends_on
            ? __('Sale until :date', ['date' => $this->sale_ends_on->format(AppServiceProvider::dateFormat())])
            : null;
    }

    /** Whether any price of the sale is set (the days alone make no sale). */
    abstract protected function hasSalePrices(): bool;
}
