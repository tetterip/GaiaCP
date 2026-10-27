<?php

namespace App\Filament\Actions;

use App\Models\Registrar;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\DomainName;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Read-only test of a registrar's login: looks up one domain in the account. */
class CheckDomainAction
{
    public static function make(): Action
    {
        return Action::make('checkDomain')
            ->label('Test with a domain')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->modalDescription(__('Reads the expiry date and nameservers of a domain in this account. Nothing is changed or charged.'))
            ->schema([
                TextInput::make('domain')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('example.gr'),
            ])
            ->action(function (Registrar $record, array $data) {
                $domain = DomainName::normalize($data['domain']);

                try {
                    $registrar = app(RegistrarFactory::class)->forRegistrar($record);
                    $expiry = $registrar->expiryDate(DomainName::toAscii($domain));
                    $nameservers = $registrar->nameservers(DomainName::toAscii($domain));
                } catch (ProvisioningException $e) {
                    Notification::make()->title(__('Check failed'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Connected: :domain expires :date', ['domain' => $domain, 'date' => $expiry->format(AppServiceProvider::dateFormat())]))
                    ->body(__('Nameservers: :list', ['list' => $nameservers ? implode(', ', $nameservers) : __('(none returned)')]))
                    ->success()
                    ->send();
            });
    }
}
