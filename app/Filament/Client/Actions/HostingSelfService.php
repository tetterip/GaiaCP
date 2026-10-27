<?php

namespace App\Filament\Client\Actions;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Forms\PasswordInput;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\ControlsPower;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * What a client can do on their own hosting account, each behind its switch under Settings → Client area. Panel calls
 * go through ServiceLifecycle like the admin's Account menu, so they are logged the same way (with the client's login).
 */
class HostingSelfService
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::openWebsite(), self::login(), ...array_map(self::power(...), ['start', 'shutdown', 'reboot']), self::changePassword()];
    }

    /** An active hosting account on a server whose panel plugin is on. */
    public static function onPanel(Service $record): bool
    {
        return $record->type === ServiceType::Hosting
            && $record->status === ServiceStatus::Active
            && $record->server !== null
            && filled($record->username)
            && app(PluginManager::class)->serverModules()->has($record->server->type);
    }

    public static function canLogIn(Service $record): bool
    {
        return config('gaiacp.client_area.hosting_login') && self::onPanel($record);
    }

    /**
     * The panel's Quick shortcuts the client can open (OffersShortcuts: cPanel, Plesk), each through the panel login
     * with ?to=<key>; none while "Log in to the panel" is off.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function shortcuts(Service $record): array
    {
        if (! self::canLogIn($record)) {
            return [];
        }

        $panel = rescue(fn () => app(ProvisionerFactory::class)->forServer($record->server), null, report: false);

        return $panel instanceof OffersShortcuts ? $panel->shortcuts() : [];
    }

    /** Start, Shut down, Restart of their own machine (ControlsPower: a Proxmox VPS), when its plugin lets clients. */
    public static function canControlPower(Service $record): bool
    {
        if (! self::onPanel($record)) {
            return false;
        }

        $panel = rescue(fn () => app(ProvisionerFactory::class)->forServer($record->server), null, report: false);

        return $panel instanceof ControlsPower && $panel->clientsControlPower();
    }

    public static function canChangePassword(Service $record): bool
    {
        return config('gaiacp.client_area.hosting_password') && self::onPanel($record);
    }

    /** "cPanel", "Plesk": the panel the account is on, as the client knows it ("cPanel / WHM" is the admin's side). */
    public static function panelName(Service $record): string
    {
        $label = app(PluginManager::class)->serverModule($record->server?->type)?->label();

        return $label ? Str::before($label, ' / ') : __('control panel');
    }

    /** The site itself, in a new tab: hosting with a domain, once set up. */
    private static function openWebsite(): Action
    {
        return Action::make('openWebsite')
            ->label('Open website')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('gray')
            ->visible(fn (Service $record) => $record->type === ServiceType::Hosting && filled($record->domain) && $record->status !== ServiceStatus::Pending)
            ->url(fn (Service $record) => 'https://'.$record->domain, shouldOpenInNewTab: true);
    }

    private static function login(): Action
    {
        return Action::make('panelLogin')
            ->label(fn (Service $record) => __('Log in to :panel', ['panel' => self::panelName($record)]))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (Service $record) => self::canLogIn($record))
            ->url(fn (Service $record) => route('filament.client.services.panel-login', ['service' => $record->id]), shouldOpenInNewTab: true);
    }

    private static function power(string $action): Action
    {
        [$label, $icon, $done] = match ($action) {
            'start' => [__('Start'), Heroicon::OutlinedPlay, __('Your VPS is starting.')],
            'shutdown' => [__('Shut down'), Heroicon::OutlinedPower, __('Your VPS was shut down.')],
            'reboot' => [__('Restart'), Heroicon::OutlinedArrowPath, __('Your VPS is restarting.')],
        };

        return Action::make("power_{$action}")
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (Service $record) => self::canControlPower($record))
            ->requiresConfirmation($action !== 'start')
            ->modalDescription($action === 'start' ? null : __('Programs running on it stop, and anything not saved is lost.'))
            ->action(function (Service $record) use ($action, $done) {
                try {
                    app(ServiceLifecycle::class)->power($record, $action);
                } catch (ProvisioningException) {
                    Notification::make()
                        ->title(__('That did not work.'))
                        ->body(__('Try again in a few minutes. Contact us if it keeps failing.'))
                        ->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title($done)->success()->send();
            });
    }

    private static function changePassword(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Service $record) => self::canChangePassword($record))
            ->modalDescription(fn (Service $record) => self::isPlesk($record)
                ? __('The password of :username, the FTP and SSH user of :domain. Your Plesk login stays the same.', ['username' => $record->username, 'domain' => $record->domain])
                : __('The password of :username, for :panel, FTP and SSH. Programs that log in with the old one (an FTP program, for example) will need the new one.', ['username' => $record->username, 'panel' => self::panelName($record)]))
            ->schema([
                PasswordInput::make('password')
                    ->label('New password')
                    ->required()
                    ->rule(Password::min(10)->letters()->mixedCase()->numbers())
                    ->helperText(__('At least 10 characters, with capital and small letters and numbers.')),
            ])
            ->modalSubmitActionLabel(__('Change password'))
            ->action(function (Service $record, array $data) {
                try {
                    app(ServiceLifecycle::class)->changePassword($record, $data['password']);
                } catch (ProvisioningException) {
                    Notification::make()
                        ->title(__('We could not change the password.'))
                        ->body(__('The server may not accept it: try a longer one, or try again later. Contact us if it keeps failing.'))
                        ->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('Password changed'))->success()->send();
            });
    }

    private static function isPlesk(Service $record): bool
    {
        return $record->server?->type === 'plesk';
    }
}
