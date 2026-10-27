<?php

namespace App\Filament\Actions;

use App\Actions\ServiceLifecycle;
use App\Actions\SetUpService;
use App\Enums\ServiceStatus;
use App\Filament\Forms\PackageSelect;
use App\Filament\Forms\PasswordInput;
use App\Models\Service;
use App\Provisioning\ControlsPower;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The "Account" menu on a service: every action goes through ServiceLifecycle,
 * so panel calls, status changes and logging stay in one place.
 */
class ServiceLifecycleActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            self::create(),
            self::changePackage(),
            self::changePassword(),
            ...array_map(self::power(...), ControlsPower::ACTIONS),
            self::suspend(),
            self::unsuspend(),
            self::terminate(),
        ])
            ->label('Account')
            ->icon(Heroicon::OutlinedServerStack)
            ->button()
            ->color('gray');
    }

    private static function create(): Action
    {
        return Action::make('createAccount')
            ->label('Create on server')
            ->icon(Heroicon::OutlinedPlusCircle)
            // On an active or suspended service too: after moving it to another server, create it there.
            ->visible(fn (Service $record) => self::onPanel($record) && in_array($record->status, [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended], true))
            ->requiresConfirmation()
            ->modalHeading(__('Create on server'))
            ->modalDescription(fn (Service $record) => $record->status === ServiceStatus::Pending
                ? __('Creates :domain with package ":package" on :server. A username and password are generated if empty.', [
                    'domain' => $record->domain ?: __('(no domain!)'),
                    'package' => $record->package ?: __('(no package!)'),
                    'server' => $record->server->name,
                ])
                : __('Creates the account :username for :domain with package ":package" on :server, with the password stored here. Use it after moving the service to another server; nothing is changed on the old server.', [
                    'username' => $record->username ?: __('(generated)'),
                    'domain' => $record->domain ?: __('(no domain!)'),
                    'package' => $record->package ?: __('(no package!)'),
                    'server' => $record->server->name,
                ]))
            ->schema(fn (Service $record) => $record->status === ServiceStatus::Pending ? [] : [
                Toggle::make('send_welcome')
                    ->label('Email the client the hosting welcome message with the new details')
                    ->default(false),
            ])
            ->action(self::run(
                fn (Service $record, ServiceLifecycle $lifecycle, array $data) => $record->status === ServiceStatus::Pending
                    ? app(SetUpService::class)->handle($record)
                    : $lifecycle->create($record, (bool) ($data['send_welcome'] ?? false)),
                __('Account created'),
            ));
    }

    private static function suspend(): Action
    {
        return Action::make('suspend')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            // A hosting account is checked on its panel, so it can be suspended there even when the status says suspended.
            ->visible(fn (Service $record) => $record->status === ServiceStatus::Active || ($record->status === ServiceStatus::Suspended && self::onPanel($record)))
            ->schema([
                Select::make('reason_type')
                    ->label('Reason')
                    ->options([Service::SUSPENDED_OVERDUE => __('Overdue payment (a payment unsuspends it)'), 'other' => __('Other')])
                    ->default(Service::SUSPENDED_OVERDUE)
                    ->required()
                    ->live(),
                TextInput::make('reason')
                    ->label('Describe the reason')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get) => $get('reason_type') === 'other'),
            ])
            ->action(self::run(
                fn (Service $record, ServiceLifecycle $lifecycle, array $data) => $lifecycle->suspend(
                    $record,
                    $data['reason_type'] === Service::SUSPENDED_OVERDUE ? Service::SUSPENDED_OVERDUE : $data['reason'],
                ),
                __('Service suspended'),
            ));
    }

    private static function unsuspend(): Action
    {
        return Action::make('unsuspend')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn (Service $record) => $record->status === ServiceStatus::Suspended || ($record->status === ServiceStatus::Active && self::onPanel($record)))
            ->requiresConfirmation()
            ->action(self::run(fn (Service $record, ServiceLifecycle $lifecycle) => $lifecycle->unsuspend($record), __('Service unsuspended')));
    }

    private static function changePackage(): Action
    {
        return Action::make('changePackage')
            ->label('Change package')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->visible(fn (Service $record) => $record->type->needsServer() && in_array($record->status, [ServiceStatus::Active, ServiceStatus::Suspended], true))
            ->schema([
                PackageSelect::make(fn (Service $record) => $record->server_id)
                    ->required()
                    ->default(fn (Service $record) => $record->package)
                    ->helperText(__('Suggestions come from the server. The price is not changed; edit it on the service if needed.')),
            ])
            ->action(self::run(fn (Service $record, ServiceLifecycle $lifecycle, array $data) => $lifecycle->changePackage($record, $data['package']), __('Package changed')));
    }

    private static function changePassword(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (Service $record) => self::onPanel($record) && in_array($record->status, [ServiceStatus::Active, ServiceStatus::Suspended], true))
            ->schema([
                PasswordInput::make()
                    ->label('New password')
                    ->required()
                    ->minLength(8)
                    ->default(fn () => PasswordInput::generate())
                    ->helperText(__('Set on the server, then stored here. A strong one is suggested.')),
            ])
            ->action(self::run(fn (Service $record, ServiceLifecycle $lifecycle, array $data) => $lifecycle->changePassword($record, $data['password']), __('Password changed')));
    }

    /** Start, Shut down, Restart, Force stop: for a machine (ControlsPower: a Proxmox VPS), while active. */
    private static function power(string $action): Action
    {
        [$label, $icon, $done] = match ($action) {
            'start' => [__('Start'), Heroicon::OutlinedPlay, __('Started')],
            'shutdown' => [__('Shut down'), Heroicon::OutlinedPower, __('Shut down')],
            'reboot' => [__('Restart'), Heroicon::OutlinedArrowPath, __('Restarted')],
            'stop' => [__('Force stop'), Heroicon::OutlinedStop, __('Stopped')],
        };

        return Action::make("power_{$action}")
            ->label($label)
            ->icon($icon)
            ->visible(fn (Service $record) => self::controlsPower($record))
            ->requiresConfirmation($action !== 'start')
            ->modalDescription($action === 'stop' ? __('Cuts the power at once, like pulling the plug: unsaved data inside the VPS is lost. Use Shut down when it still answers.') : null)
            ->action(self::run(fn (Service $record, ServiceLifecycle $lifecycle) => $lifecycle->power($record, $action), $done));
    }

    /** An active account whose panel can start and stop it. */
    public static function controlsPower(Service $record): bool
    {
        return self::onPanel($record)
            && $record->status === ServiceStatus::Active
            && rescue(fn () => app(ProvisionerFactory::class)->forServer($record->server), null, report: false) instanceof ControlsPower;
    }

    private static function terminate(): Action
    {
        return Action::make('terminate')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Service $record) => ! in_array($record->status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true))
            ->requiresConfirmation()
            ->modalHeading(__('Terminate service'))
            ->modalDescription(fn (Service $record) => $record->type->needsServer() && $record->server_id && $record->status !== ServiceStatus::Pending
                ? __('This permanently deletes the account :username and all its files, email and databases from :server. It cannot be undone.', ['username' => $record->username, 'server' => $record->server->name])
                : __('Marks the service as terminated; no reminders are sent for it any more.'))
            ->schema(fn (Service $record) => $record->type->needsServer() && $record->server_id && $record->status !== ServiceStatus::Pending ? [
                TextInput::make('confirm_username')
                    ->label(__('Type :username to confirm', ['username' => $record->username]))
                    ->required()
                    ->in([$record->username]),
            ] : [])
            ->action(self::run(fn (Service $record, ServiceLifecycle $lifecycle) => $lifecycle->terminate($record), __('Service terminated')));
    }

    private static function onPanel(Service $record): bool
    {
        return $record->type->needsServer() && $record->server_id !== null;
    }

    /**
     * @param  Closure(Service, ServiceLifecycle, array<string, mixed>): void  $call
     */
    private static function run(Closure $call, string $success): Closure
    {
        return function (Service $record, array $data, $livewire) use ($call, $success) {
            try {
                $call($record, app(ServiceLifecycle::class), $data);
            } catch (ProvisioningException $e) {
                Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

                return;
            }

            Notification::make()->title($success)->success()->send();

            if (method_exists($livewire, 'refreshFormData')) {
                $livewire->refreshFormData(['status', 'suspension_reason', 'username', 'password', 'package']);
            }
        };
    }
}
