<?php

namespace App\Filament\Actions;

use App\Actions\DomainLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Actions\DomainSelfService;
use App\Filament\Forms\DomainContactsForm;
use App\Models\Service;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Contact details, private nameservers and the registrar lock of a domain, for the admin's Registrar menu and the
 * client's domain page (each behind its switch under Settings → Client area). Only for registrars that can do them
 * (App\Provisioning\Manages*); every call goes through DomainLifecycle, so it is logged. The admin sees the
 * registrar's answer when one fails, the client a plain message.
 */
class DomainRegistrarActions
{
    /** @return list<Action> */
    public static function make(bool $forClient = false): array
    {
        return [self::contacts($forClient), self::childNameservers($forClient), self::lock($forClient)];
    }

    private static function available(Service $record, bool $forClient, string $switch, string $capability): bool
    {
        $usable = $forClient
            ? config("gaiacp.client_area.{$switch}") && DomainSelfService::atRegistrar($record)
            : $record->type === ServiceType::Domain && in_array($record->status, ServiceStatus::billable(), true);

        return $usable && app(DomainLifecycle::class)->supports($record, $capability);
    }

    public static function contacts(bool $forClient = false): Action
    {
        return Action::make('domainContacts')
            ->label('Contact details')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->visible(fn (Service $record) => self::available($record, $forClient, 'domain_contacts', ManagesDomainContacts::class))
            ->modalHeading(fn (Service $record) => __('Contact details of :domain', ['domain' => $record->domain]))
            ->modalDescription(__('As the registrar has them. The owner is who the domain belongs to; some registries don\'t allow changing the owner\'s name here.'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->mountUsing(function (Service $record, $schema, Action $action) use ($forClient) {
                try {
                    $schema->fill(DomainContactsForm::state(app(DomainLifecycle::class)->contacts($record)));
                } catch (ProvisioningException $e) {
                    self::failed($forClient ? __('We could not read the contact details.') : __('Failed'), $forClient ? null : $e->getMessage());
                    $action->cancel();
                }
            })
            ->schema(fn (Service $record) => [
                DomainContactsForm::tabs(app(DomainLifecycle::class)->contactRoles($record), $record->client, $forClient ? __('Use my account details') : __('Use the client\'s details')),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (Service $record, array $data) use ($forClient) {
                try {
                    app(DomainLifecycle::class)->saveContacts($record, DomainContactsForm::contacts($data, app(DomainLifecycle::class)->contactRoles($record)));
                } catch (ProvisioningException $e) {
                    self::failed($forClient ? __('We could not change the contact details.') : __('Failed'), $forClient ? null : $e->getMessage());

                    return;
                }

                Notification::make()->title(__('Contact details saved'))->success()->send();
            });
    }

    public static function childNameservers(bool $forClient = false): Action
    {
        return Action::make('childNameservers')
            ->label('Private nameservers')
            ->icon(Heroicon::OutlinedServerStack)
            ->color('gray')
            ->visible(fn (Service $record) => self::available($record, $forClient, 'domain_child_nameservers', ManagesChildNameservers::class))
            ->modalHeading(__('Private nameservers'))
            ->modalDescription(fn (Service $record) => __('Nameservers named after :domain (e.g. ns1.:domain), registered with their IP address so they can be used as nameservers by this or other domains.', ['domain' => $record->domain]))
            ->schema(fn (Service $record) => [
                Text::make(fn () => self::registered($record)),
                ToggleButtons::make('operation')
                    ->hiddenLabel()
                    ->options(['save' => __('Register or change'), 'delete' => __('Delete')])
                    ->default('save')
                    ->inline()
                    ->live()
                    ->required(),
                TextInput::make('host')
                    ->label('Nameserver')
                    ->required()
                    ->maxLength(63)
                    ->regex('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/')
                    ->suffix('.'.$record->domain)
                    ->datalist(collect($record->registration()->child_nameservers ?? [])->map(fn (array $ns) => substr($ns['host'], 0, -strlen((string) $record->domain) - 1))->all()),
                TextInput::make('ip')
                    ->label('IP address')
                    ->ip()
                    ->required(fn (Get $get) => $get('operation') !== 'delete')
                    ->visible(fn (Get $get) => $get('operation') !== 'delete'),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (Service $record, array $data) use ($forClient) {
                $host = trim((string) $data['host']);

                try {
                    $data['operation'] === 'delete'
                        ? app(DomainLifecycle::class)->deleteChildNameserver($record, $host)
                        : app(DomainLifecycle::class)->saveChildNameserver($record, $host, (string) $data['ip']);
                } catch (ProvisioningException $e) {
                    self::failed($forClient ? __('We could not save the private nameserver.') : __('Failed'), $forClient ? null : $e->getMessage());

                    return;
                }

                Notification::make()->title($data['operation'] === 'delete' ? __('Private nameserver deleted') : __('Private nameserver saved'))->success()->send();
            });
    }

    public static function lock(bool $forClient = false): Action
    {
        return Action::make('registrarLock')
            ->label('Registrar lock')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->visible(fn (Service $record) => self::available($record, $forClient, 'domain_lock', ManagesRegistrarLock::class))
            ->modalDescription(__('A locked domain can\'t be transferred to another provider. Unlock it only just before a transfer.'))
            ->mountUsing(function (Service $record, $schema, Action $action) use ($forClient) {
                try {
                    $schema->fill(['locked' => app(DomainLifecycle::class)->locked($record)]);
                } catch (ProvisioningException $e) {
                    self::failed($forClient ? __('We could not read the registrar lock.') : __('Failed'), $forClient ? null : $e->getMessage());
                    $action->cancel();
                }
            })
            ->schema([
                Toggle::make('locked')->label('Locked'),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (Service $record, array $data) use ($forClient) {
                try {
                    app(DomainLifecycle::class)->setLocked($record, (bool) $data['locked']);
                } catch (ProvisioningException $e) {
                    self::failed($forClient ? __('We could not change the registrar lock.') : __('Failed'), $forClient ? null : $e->getMessage());

                    return;
                }

                Notification::make()->title($data['locked'] ? __('The domain is locked') : __('The domain is unlocked'))->success()->send();
            });
    }

    private static function registered(Service $record): string
    {
        $registered = collect($record->registration()->child_nameservers ?? [])->map(fn (array $ns) => "{$ns['host']} ({$ns['ip']})");

        return $registered->isEmpty()
            ? __('None registered from here yet.')
            : __('Registered from here: :list', ['list' => $registered->implode(', ')]);
    }

    private static function failed(string $title, ?string $body): void
    {
        Notification::make()->title($title)->body($body ?? __('Please try again later, or contact us.'))->danger()->persistent()->send();
    }
}
