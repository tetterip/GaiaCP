<?php

namespace App\Filament\Actions;

use App\Actions\DomainLifecycle;
use App\Actions\SetUpService;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Forms\NameserversInput;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The "Registrar" menu on a domain service: every action goes through DomainLifecycle,
 * so registrar calls, status changes and logging stay in one place.
 */
class DomainActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            self::activate(),
            self::sync(),
            self::nameservers(),
            self::eppCode(),
            ...DomainRegistrarActions::make(),
        ])
            ->label('Registrar')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->button()
            ->color('gray');
    }

    private static function activate(): Action
    {
        return Action::make('activateDomain')
            ->label(fn (Service $record) => match ($record->registration()->registration_type) {
                DomainRegistrationType::Register => 'Register domain',
                DomainRegistrationType::Transfer => 'Request transfer',
                DomainRegistrationType::External => 'Mark active',
            })
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (Service $record) => self::isDomain($record) && $record->status === ServiceStatus::Pending)
            ->requiresConfirmation()
            ->modalDescription(fn (Service $record) => self::activateDescription($record))
            ->action(self::run(fn (Service $record) => app(SetUpService::class)->handle($record), __('Done')));
    }

    private static function sync(): Action
    {
        return Action::make('syncDomain')
            ->label('Sync expiry and nameservers')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Service $record) => self::isDomain($record) && $record->status !== ServiceStatus::Pending && app(DomainLifecycle::class)->readsRegistrar($record))
            ->action(self::run(fn (Service $record, DomainLifecycle $domains) => $domains->sync($record), __('Synced')));
    }

    private static function nameservers(): Action
    {
        return Action::make('setNameservers')
            ->label('Change nameservers')
            ->icon(Heroicon::OutlinedServer)
            ->visible(fn (Service $record) => self::isDomain($record) && in_array($record->status, ServiceStatus::billable(), true))
            ->schema([
                NameserversInput::make()
                    ->required()
                    ->default(fn (Service $record) => $record->registration()->nameservers ?? [])
                    ->helperText(__('Two to four. Sync first to see the current ones.')),
            ])
            ->action(self::run(fn (Service $record, DomainLifecycle $domains, array $data) => $domains->setNameservers($record, $data['nameservers']), __('Nameservers changed')));
    }

    private static function eppCode(): Action
    {
        return Action::make('eppCode')
            ->label('Get EPP code')
            ->icon(Heroicon::OutlinedKey)
            // Email Notifications has no code to read: the admin gets it from wherever the domain is.
            ->visible(fn (Service $record) => self::isDomain($record) && in_array($record->status, ServiceStatus::billable(), true) && app(DomainLifecycle::class)->readsRegistrar($record))
            ->requiresConfirmation()
            ->modalDescription(fn (Service $record) => app(DomainLifecycle::class)->emailsEppCode($record)
                ? __('Asks the registrar to email the transfer-out code to the domain owner\'s email address. Nothing is changed.')
                : __('Reads the transfer-out code from the registrar. Nothing is changed.'))
            ->action(function (Service $record) {
                try {
                    $code = app(DomainLifecycle::class)->eppCode($record);
                } catch (ProvisioningException $e) {
                    Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                if ($code === null) {
                    // A registrar that emails it (Enom): the client gets it at the domain's registrant email.
                    Notification::make()->title(__('EPP code emailed'))->body(__('The registrar emailed the EPP code of :domain to the domain owner\'s email address.', ['domain' => $record->domain]))->success()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('EPP code of :domain', ['domain' => $record->domain]))->body($code)->success()->persistent()->send();
            });
    }

    private static function isDomain(Service $record): bool
    {
        return $record->type === ServiceType::Domain;
    }

    private static function activateDescription(Service $record): string
    {
        $details = $record->registration();
        $registrar = $details->registrarAccount()?->name ?? __('(no registrar for this TLD!)');

        return match ($details->registration_type) {
            DomainRegistrationType::Register => __('Registers :domain for :years year(s) at :registrar, which charges your registrar account.', ['domain' => $record->domain, 'years' => $record->registrationYears(), 'registrar' => $registrar]),
            DomainRegistrationType::Transfer => __('Requests the transfer of :domain to :registrar with the EPP code on the form. The registrar may charge for it.', ['domain' => $record->domain, 'registrar' => $registrar]),
            DomainRegistrationType::External => __('Marks :domain active without calling a registrar.', ['domain' => $record->domain]),
        };
    }

    /**
     * @param  Closure(Service, DomainLifecycle, array<string, mixed>): void  $call
     */
    private static function run(Closure $call, string $success): Closure
    {
        return function (Service $record, array $data, $livewire) use ($call, $success) {
            try {
                $call($record, app(DomainLifecycle::class), $data);
            } catch (ProvisioningException $e) {
                Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

                return;
            }

            $expires = $record->fresh('domainDetails')->domainDetails?->expires_at;
            Notification::make()->title($success)
                ->body($expires ? __('Expires on :date', ['date' => $expires->format(AppServiceProvider::dateFormat())]) : null)
                ->success()->send();

            if (method_exists($livewire, 'refreshFormData')) {
                $livewire->refreshFormData(['status', 'domainDetails']);
            }
        };
    }
}
