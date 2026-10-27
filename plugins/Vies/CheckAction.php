<?php

namespace Plugins\Vies;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/** The check mark next to the VAT number on the client form. */
final class CheckAction
{
    /** Asks VIES about the VAT number and country in the form, fills an empty company name; nothing else is saved. */
    public static function make(): Action
    {
        return Action::make('viesCheck')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->tooltip(__('Check in VIES'))
            ->visible(fn () => Vies::enabled())
            ->action(function (Get $get, Set $set): void {
                try {
                    $check = Vies::check($get('vat_number'), $get('country'));
                } catch (ViesException $e) {
                    Notification::make()->danger()->title(__('VIES check failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                if (! $check->valid) {
                    Notification::make()->warning()->persistent()
                        ->title(__('Not valid in VIES: :number', ['number' => $check->country_code.$check->vat_number]))
                        ->body(__('This client is not treated as an EU business for the reverse charge.'))
                        ->send();

                    return;
                }

                if (filled($check->name) && blank($get('company'))) {
                    $set('company', $check->name);
                }

                Notification::make()->success()
                    ->title(__('Valid in VIES: :number', ['number' => $check->country_code.$check->vat_number]))
                    ->body(collect([$check->name, $check->address, $check->request_identifier ? __('Consultation number :id', ['id' => $check->request_identifier]) : null])->filter()->implode("\n"))
                    ->send();
            });
    }
}
