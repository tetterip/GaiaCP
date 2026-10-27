<?php

namespace App\Filament\Client\Actions;

use App\Actions\DomainLifecycle;
use App\Actions\SetDomainAutoRenew;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Actions\DomainRegistrarActions;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * What a client can do on their own domain, each behind its switch under Settings → Client area. Registrar calls go
 * through DomainLifecycle like the admin's Registrar menu, so they are logged the same way (with the client's login).
 * A failure shows the client a plain message; the registrar's answer stays in the activity log.
 */
class DomainSelfService
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::nameservers(), self::eppCode(), self::autoRenew(), ...DomainRegistrarActions::make(forClient: true)];
    }

    /** Active and at a registrar here: domains registered elsewhere (tracked only) have nothing to call. */
    public static function atRegistrar(Service $record): bool
    {
        return $record->status === ServiceStatus::Active
            && $record->registration()->registration_type !== DomainRegistrationType::External
            && $record->registration()->registrarAccount() !== null;
    }

    private static function nameservers(): Action
    {
        return Action::make('changeNameservers')
            ->label('Change nameservers')
            ->icon(Heroicon::OutlinedServer)
            ->visible(fn (Service $record) => config('gaiacp.client_area.domain_nameservers') && self::atRegistrar($record))
            ->modalDescription(__('Two to four, as your hosting provider gives them. The change can take up to a day to reach everyone.'))
            ->fillForm(fn (Service $record) => collect(range(1, 4))
                ->mapWithKeys(fn (int $i) => ["ns{$i}" => $record->registration()->nameservers[$i - 1] ?? null])
                ->all())
            ->schema([
                Grid::make(2)->schema(collect(range(1, 4))->map(fn (int $i) => TextInput::make("ns{$i}")
                    ->label(__('Nameserver :number', ['number' => $i]))
                    ->required($i <= 2)
                    ->maxLength(253)
                    ->rule(fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($get, $i) {
                        if (filled($value) && ! DomainName::isNameserver((string) $value)) {
                            $fail(__('Enter a name like ns1.example.com.'));

                            return;
                        }

                        $earlier = array_map(fn (int $j) => strtolower(rtrim(trim((string) $get("ns{$j}")), '.')), $i > 1 ? range(1, $i - 1) : []);

                        if (filled($value) && in_array(strtolower(rtrim(trim($value), '.')), $earlier, true)) {
                            $fail(__('This nameserver is already in the list.'));
                        }
                    })
                )->all()),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (Service $record, array $data, $livewire) {
                $nameservers = array_values(array_filter(array_map(fn (int $i) => rtrim(trim((string) ($data["ns{$i}"] ?? '')), '.'), range(1, 4))));

                try {
                    app(DomainLifecycle::class)->setNameservers($record, $nameservers);
                } catch (ProvisioningException) {
                    self::failed(__('We could not change the nameservers.'));

                    return;
                }

                // At Email Notifications the admins make the change by hand.
                Notification::make()->title(self::manual($record) ? __('Change requested') : __('Nameservers changed'))
                    ->body(self::manual($record) ? __('We will make the change shortly.') : null)
                    ->success()->send();
                $livewire->record->refresh();
            });
    }

    private static function eppCode(): Action
    {
        return Action::make('getEppCode')
            ->label('Get EPP code')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Service $record) => config('gaiacp.client_area.domain_epp') && self::atRegistrar($record))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalHeading(__('Get EPP code'))
            ->modalDescription(__('The EPP code lets another provider take over this domain. Give it only to the one you are moving the domain to.'))
            ->modalSubmitActionLabel(fn (Service $record) => self::manual($record) || app(DomainLifecycle::class)->emailsEppCode($record) ? __('Request the code') : __('Show the code'))
            ->action(function (Service $record) {
                try {
                    $code = app(DomainLifecycle::class)->eppCode($record);
                } catch (ProvisioningException) {
                    self::failed(__('We could not get the EPP code.'));

                    return;
                }

                if ($code === null) {
                    // Email Notifications: the admins were asked for it; a registrar like Enom emailed it to the owner.
                    Notification::make()->title(__('EPP code requested'))
                        ->body(self::manual($record)
                            ? __('We will email you the EPP code of :domain.', ['domain' => $record->domain])
                            : __('The EPP code of :domain has been emailed to the domain owner\'s email address.', ['domain' => $record->domain]))
                        ->success()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('EPP code of :domain', ['domain' => $record->domain]))->body($code)->success()->persistent()->send();
            });
    }

    /** At a registrar that books nothing (Email Notifications): requests go to the admins by email. */
    private static function manual(Service $record): bool
    {
        return (bool) $record->registration()->registrarAccount()?->isManual();
    }

    private static function autoRenew(): Action
    {
        return Action::make('toggleAutoRenew')
            ->label(fn (Service $record) => $record->auto_renew ? 'Turn off auto renew' : 'Turn on auto renew')
            ->icon(fn (Service $record) => $record->auto_renew ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedArrowPath)
            ->visible(fn (Service $record) => config('gaiacp.client_area.domain_auto_renew') && in_array($record->status, ServiceStatus::billable(), true))
            ->requiresConfirmation()
            ->modalHeading(fn (Service $record) => $record->auto_renew ? __('Turn off auto renew') : __('Turn on auto renew'))
            ->modalDescription(fn (Service $record) => $record->auto_renew
                ? __('You will get no more payment notices for :domain and it ends on :date. You can turn it back on before then.', ['domain' => $record->domain, 'date' => $record->next_due_date->format(AppServiceProvider::dateFormat())])
                : __(':domain renews again: you get a payment notice before :date.', ['domain' => $record->domain, 'date' => $record->next_due_date->format(AppServiceProvider::dateFormat())]))
            ->color('gray')
            ->modalSubmitActionLabel(fn (Service $record) => $record->auto_renew ? __('Turn off') : __('Turn on'))
            ->modalSubmitAction(fn ($action, Service $record) => $action->color($record->auto_renew ? 'danger' : 'primary'))
            ->action(function (Service $record, $livewire) {
                $on = ! $record->auto_renew;

                app(SetDomainAutoRenew::class)->handle($record, $on);

                Notification::make()->title($on ? __('Auto renew is on') : __('Auto renew is off'))->success()->send();
                $livewire->record->refresh();
            });
    }

    private static function failed(string $title): void
    {
        Notification::make()->title($title)->body(__('Please try again later, or contact us.'))->danger()->persistent()->send();
    }
}
