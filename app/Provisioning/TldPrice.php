<?php

namespace App\Provisioning;

/**
 * A registrar's cost for one TLD (ListsTldPrices), in cents of $currency, for a term of $years: to register, renew
 * and transfer it, and to restore it from redemption. Null = the registrar did not say.
 */
final readonly class TldPrice
{
    public function __construct(
        public string $tld,
        public int $years,
        public string $currency,
        public ?int $registerCents,
        public ?int $renewCents,
        public ?int $transferCents,
        public ?int $redemptionCents = null,
    ) {}

    /** @return array{tld: string, years: int, currency: string, register: ?int, renew: ?int, transfer: ?int, redemption: ?int} plain, so it can be cached */
    public function toArray(): array
    {
        return [
            'tld' => $this->tld,
            'years' => $this->years,
            'currency' => $this->currency,
            'register' => $this->registerCents,
            'renew' => $this->renewCents,
            'transfer' => $this->transferCents,
            'redemption' => $this->redemptionCents,
        ];
    }

    /** A price from the registrar's reply ("8.95", 8.95, "") in cents; null when missing, not a number or not above zero. */
    public static function cents(mixed $amount): ?int
    {
        if (! is_numeric($amount = is_string($amount) ? str_replace(',', '.', trim($amount)) : $amount)) {
            return null;
        }

        $cents = (int) round((float) $amount * 100);

        return $cents > 0 ? $cents : null;
    }
}
