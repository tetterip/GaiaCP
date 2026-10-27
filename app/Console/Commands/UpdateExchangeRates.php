<?php

namespace App\Console\Commands;

use App\Support\Currencies;
use App\Support\ExchangeRates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('gaiacp:update-exchange-rates {--prices : Also set the prices in other currencies at the new rates}')]
#[Description('Read the European Central Bank\'s rates for every currency on Settings → Currencies (daily when Settings → Automation says so)')]
class UpdateExchangeRates extends Command
{
    public function handle(ExchangeRates $rates): int
    {
        if (! Currencies::multiple()) {
            $this->info('Only the base currency is set up; nothing to update.');

            return self::SUCCESS;
        }

        try {
            $result = $rates->update();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['updated'] as $code => $rate) {
            $this->line("{$code}: {$rate}");
        }

        foreach ($result['missing'] as $code) {
            $this->warn("{$code}: not quoted by the ECB, left as it is");
        }

        if ($this->option('prices')) {
            $this->info($rates->updatePrices().' product(s) and TLD(s) repriced.');
        }

        return self::SUCCESS;
    }
}
