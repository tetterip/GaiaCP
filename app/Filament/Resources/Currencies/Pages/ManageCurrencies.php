<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Support\Currencies;
use App\Support\ExchangeRates;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ManageCurrencies extends ManageRecords
{
    protected static string $resource = CurrencyResource::class;

    public function getSubheading(): ?string
    {
        return __('Each client is billed in one currency (on the client\'s page). Prices are entered in :base; in another currency they are set on each product and TLD, or follow the rate.', ['base' => Currencies::base()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateRates')
                ->label('Update exchange rates')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => Currencies::multiple())
                ->modalDescription(__('Reads today\'s reference rates of the European Central Bank and sets them on every currency it quotes.'))
                ->action(function (ExchangeRates $rates) {
                    try {
                        $result = $rates->update();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Exchange rates not updated'))->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Exchange rates updated'))
                        ->body($result['missing'] ? __('Not quoted by the European Central Bank, left as they were: :codes', ['codes' => implode(', ', $result['missing'])]) : null)
                        ->send();
                }),
            Action::make('updatePrices')
                ->label('Update product prices')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->visible(fn () => Currencies::multiple())
                ->requiresConfirmation()
                ->modalDescription(__('Sets every product\'s and TLD\'s prices in the other currencies to their :base prices at today\'s rates, replacing the ones set by hand. Services already here keep their prices.', ['base' => Currencies::base()]))
                ->action(function (ExchangeRates $rates) {
                    $count = $rates->updatePrices();
                    Notification::make()->success()->title(__('Prices updated'))->body(__(':count products and TLDs repriced.', ['count' => $count]))->send();
                }),
            CreateAction::make()
                ->label('Add currency')
                ->after(fn (Currency $record) => ActivityLog::record('currency.created', "Currency {$record->code} added (rate {$record->rate})")),
        ];
    }
}
