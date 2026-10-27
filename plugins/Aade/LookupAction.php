<?php

namespace Plugins\Aade;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/** The magnifying glass next to the ΑΦΜ on the client form. */
final class LookupAction
{
    /** Fills the company, tax and address fields from the ΑΑΔΕ registry (Settings → ΑΑΔΕ); nothing is saved until Save. */
    public static function make(): Action
    {
        return Action::make('aadeLookup')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->tooltip(__('Fill in from ΑΑΔΕ'))
            ->visible(fn () => AadeRegistry::enabled())
            ->action(function (Get $get, Set $set): void {
                if (blank($get('vat_number'))) {
                    Notification::make()->warning()->title(__('Enter the ΑΦΜ first'))->send();

                    return;
                }

                try {
                    $result = AadeRegistry::lookup((string) $get('vat_number'));
                } catch (AadeException $e) {
                    Notification::make()->danger()->title(__('ΑΑΔΕ lookup failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                $fields = [
                    'vat_number' => $result['afm'] ?: null,
                    'company' => $result['name'],
                    'tax_office' => $result['tax_office'],
                    'profession' => $result['profession'],
                    'address1' => $result['address'],
                    'postcode' => $result['postcode'],
                    'city' => $result['city'],
                ];

                foreach (array_filter($fields, 'filled') as $field => $value) {
                    $set($field, $value);
                }

                if (filled($result['address'])) {
                    $set('country', 'GR');
                }

                $notification = Notification::make()->title(__('Filled in from ΑΑΔΕ: :name', ['name' => $result['name']]))
                    ->body(__('Check the details and press Save.'));

                if (! $result['active']) {
                    $notification->warning()->body(($result['status'] ?? __('This ΑΦΜ is deactivated')).'. '.__('Check the details and press Save.'))->persistent();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }
}
